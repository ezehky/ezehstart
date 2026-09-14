<?php

use App\Enums\GateAccessEnum;
use App\Enums\StatusDefault;
use App\Enums\StatusUser;
use App\Models\NotificationType;
use App\Models\User;
use App\Services\NewsletterService;
use App\Traits\WithDataTable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Who gets the newsletter.
 *
 * Two kinds of row, one list — which is the point of not having a subscribers
 * table. An account that switched announcements on and an address that only ever
 * gave us an address sit side by side, because a send does not distinguish between
 * them and neither should the screen that reports on it.
 *
 * The switch here is the same notification preference the member edits on their own
 * settings page. Turning it off from this screen is an administrator unsubscribing
 * somebody, not a separate flag that would have to agree with theirs.
 */
new class extends Component
{
    use WithDataTable, WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    /**
     * all | subscribed | unsubscribed | list-only
     */
    #[Url]
    public string $membership = 'subscribed';

    public function mount(): void
    {
        kSetSiteTitle('users', 'newsletter');
        $this->setPageGate('users.newsletter');
    }

    protected function tableColumns(): array
    {
        return [
            'name' => $this->columnMaker('Name', locked: true),
            'email' => $this->columnMaker('Address'),
            'kind' => $this->columnMaker('Kind', sortable: false),
            'subscribed' => $this->columnMaker('Newsletter', sortable: false),
            'created_at' => $this->columnMaker('Added', sortable: true),
        ];
    }

    protected function tableQuery(): Builder
    {
        $typeId = $this->typeId;

        $query = User::query()
            // Everybody who has a newsletter switch at all. An account created
            // before the announcements type existed has none yet, and has nothing
            // to report until the backfill gives it one.
            ->whereHas('notificationPreferences', fn (Builder $row) => $row->where('notification_type_id', $typeId))
            ->with([
                'notificationPreferences' => fn ($row) => $row->where('notification_type_id', $typeId),
            ])
            ->when($this->search !== '', fn (Builder $inner) => $inner->searchMacro(['name', 'email'], $this->search));

        $query = match ($this->membership) {
            'subscribed' => $query->whereHas(
                'notificationPreferences',
                fn (Builder $row) => $row->where('notification_type_id', $typeId)->where('status', StatusDefault::ACTIVE),
            ),
            'unsubscribed' => $query->whereHas(
                'notificationPreferences',
                fn (Builder $row) => $row->where('notification_type_id', $typeId)->where('status', StatusDefault::INACTIVE),
            ),
            // Addresses that never opened an account — the mailing list proper.
            'list-only' => $query->newsletterSubscribers(),
            default => $query,
        };

        return $this->applyDateRange($query, 'created_at');
    }

    protected function tableSubject(): string
    {
        return 'newsletter subscribers';
    }

    protected function tableFilters(): array
    {
        return [
            'search' => $this->filterMaker('Search'),
            'membership' => $this->filterMaker('Showing', $this->membershipOptions()),
        ];
    }

    protected function tableDateLabel(): string
    {
        return 'Added';
    }

    protected function tableExportValue(Model $item, string $column): mixed
    {
        return match ($column) {
            'kind' => $item->status->isNewsletterSubscriber() ? 'List only' : 'Account',
            'subscribed' => $this->isSubscribed($item) ? 'Yes' : 'No',
            'created_at' => $item->createdAtHuman(),
            default => $this->defaultExportValue($item, $column),
        };
    }

    protected function tablePerPage(): int
    {
        return 15;
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedMembership(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function subscribers()
    {
        return $this->applySort($this->tableQuery(), 'created_at')->paginate($this->tablePerPage());
    }

    /**
     * @return iterable<int, Model>
     */
    protected function tableRows(): iterable
    {
        return $this->subscribers;
    }

    public function membershipOptions(): array
    {
        return [
            'subscribed' => 'Subscribed',
            'unsubscribed' => 'Unsubscribed',
            'list-only' => 'List only',
            'all' => 'Everybody',
        ];
    }

    /**
     * The announcements type row's id, which every query on this screen needs.
     *
     * A retired type has no row, and -1 is what makes that read as "nobody" rather
     * than as "everybody" — a null in the where clause would match nothing useful
     * and a missing clause would match every preference there is.
     */
    #[Computed]
    public function typeId(): int
    {
        return (int) (NotificationType::query()
            ->where('notification_type', NewsletterService::TYPE->value)
            ->value('id') ?? -1);
    }

    public function isSubscribed(User $user): bool
    {
        return (bool) $user->notificationPreferences->first()?->status->isActive();
    }

    /**
     * Unsubscribe somebody, or put them back.
     *
     * Through the service so it writes the same preference row the member's own
     * settings page writes. A second flag for "the admin turned this off" would be
     * a second answer to a question that already has one.
     */
    public function toggle(int $userId): bool
    {
        $this->checkGate(GateAccessEnum::MODIFY);

        $user = User::query()->whereKey($userId)->first();

        $this->respondError('That account is no longer here.', if: ! $user);

        $service = app(NewsletterService::class);

        $subscribed = $service->isSubscribed($user->email);

        $subscribed
            ? $service->unsubscribe($user->email)
            : $service->subscribe($user->email);

        unset($this->subscribers, $this->metrics);

        return $this->respondSuccess($subscribed
            ? "{$user->email} will not get the newsletter."
            : "{$user->email} is back on the list.");
    }

    #[Computed]
    public function metrics(): array
    {
        $typeId = $this->typeId;

        $subscribed = fn () => User::query()->whereHas(
            'notificationPreferences',
            fn (Builder $row) => $row->where('notification_type_id', $typeId)->where('status', StatusDefault::ACTIVE),
        );

        return [
            $this->metricMaker('On the list', $subscribed()->count(), 'envelope', tone: 'emerald'),
            $this->metricMaker(
                'Accounts',
                (clone $subscribed())->where('status', '!=', StatusUser::NEWSLETTER_SUBSCRIBER)->count(),
                'user-group',
                tone: 'sky',
            ),
            $this->metricMaker(
                'Address only',
                (clone $subscribed())->where('status', StatusUser::NEWSLETTER_SUBSCRIBER)->count(),
                'at-symbol',
            ),
            $this->metricMaker(
                'Joined this month',
                User::query()->newsletterSubscribers()->where('created_at', '>=', now()->startOfMonth())->count(),
                'calendar-days',
            ),
        ];
    }
};
?>

<div class="space-y-6">
    <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4" aria-label="Newsletter metrics">
        @foreach ($this->metrics as $metric)
            <x-dashboard.stat-card :metric="$metric" />
        @endforeach
    </section>

    @if ($this->typeId < 0)
        <flux:callout icon="exclamation-triangle" color="amber" class="text-sm">
            The <strong>Announcements</strong> notification type is not in the list, so nobody
            has a newsletter switch and nothing can be sent. Re-seed the notification types to
            put it back.
        </flux:callout>
    @endif

    <flux:card class="space-y-5">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <flux:heading level="2" size="lg">Newsletter</flux:heading>
                <flux:text class="mt-1">
                    Accounts and addresses together — a send does not tell them apart.
                </flux:text>
            </div>

            <flux:input
                class="sm:min-w-60"
                wire:model.live.debounce.350ms="search"
                placeholder="Search name or address"
                icon="magnifying-glass"
            />
        </div>

        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
                <flux:select wire:model.live="membership" label="Showing" class="sm:max-w-48">
                    @foreach ($this->membershipOptions() as $value => $label)
                        <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>

                <x-form.date-field
                    mode="range"
                    wire:model.live="dateFrom"
                    end-model="dateTo"
                    with-presets
                    label="Added between"
                    class="sm:max-w-md"
                />
            </div>

            <x-table.column-manager :columns="$this->tableColumnList" />
        </div>

        <x-table.active-filters :filters="$this->tableActiveFilters" />

        <flux:table :paginate="$this->subscribers">
            <x-table.columns
                :columns="$this->tableColumnList"
                :sort="$sortColumn"
                :direction="$sortDirection"
                actions
            />

            <x-table.rows :columns="$this->tableColumnList">
                @forelse ($this->subscribers as $item)
                    @php($subscribed = $this->isSubscribed($item))

                    <flux:table.row wire:key="subscriber-{{ $item->id }}">
                        <x-table.cell column="name">
                            <div class="font-medium">{{ $item->name }}</div>
                        </x-table.cell>

                        <x-table.cell column="email">
                            <span class="text-xs text-slate-500">{{ $item->email }}</span>
                        </x-table.cell>

                        <x-table.cell column="kind">
                            @if ($item->status->isNewsletterSubscriber())
                                <flux:badge size="sm" color="zinc">List only</flux:badge>
                            @else
                                <flux:badge size="sm" color="sky">Account</flux:badge>
                            @endif
                        </x-table.cell>

                        <x-table.cell column="subscribed">
                            <flux:badge size="sm" :color="$subscribed ? 'emerald' : 'rose'">
                                {{ $subscribed ? 'Subscribed' : 'Unsubscribed' }}
                            </flux:badge>
                        </x-table.cell>

                        <x-table.cell column="created_at">{{ $item->createdAtHuman() }}</x-table.cell>

                        <x-table.cell>
                            <flux:dropdown position="right" align="start">
                                <flux:button icon="ellipsis-vertical" variant="ghost" size="sm" />
                                <flux:menu>
                                    <x-dashboard.gate.menu-item
                                        :gate="$pageGate"
                                        :level="$gateModify"
                                        :icon="$subscribed ? 'bell-slash' : 'bell'"
                                        wire:click="toggle({{ $item->id }})"
                                    >
                                        {{ $subscribed ? 'Unsubscribe' : 'Subscribe' }}
                                    </x-dashboard.gate.menu-item>

                                    {{-- Only an account has a page to open. A list-only
                                         row has nothing on it the user screen could show. --}}
                                    @unless ($item->status->isNewsletterSubscriber())
                                        <flux:menu.item
                                            icon="arrow-top-right-on-square"
                                            href="{{ route('admin.user', $item->id) }}"
                                            wire:navigate
                                        >
                                            Open account
                                        </flux:menu.item>
                                    @endunless
                                </flux:menu>
                            </flux:dropdown>
                        </x-table.cell>
                    </flux:table.row>
                @empty
                    <x-table.empty
                        :columns="$this->tableColumnList"
                        actions
                        label="Nobody here yet"
                        icon="envelope"
                        text="Addresses arrive from the sign-up form on the public pages, and from members turning announcements on."
                    />
                @endforelse
            </x-table.rows>
        </flux:table>
    </flux:card>
</div>
