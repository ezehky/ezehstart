<?php

use App\Enums\ActivityActionEnum;
use App\Enums\GateAccessEnum;
use App\Enums\StatusEmailCampaign;
use App\Models\EmailCampaign;
use App\Services\ActivityLogService;
use App\Services\EmailCampaignService;
use App\Services\EmailTemplateService;
use App\Traits\WithDataTable;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithDataTable, WithPagination;

    public ?EmailCampaign $campaign = null;

    #[Url]
    public string $search = '';

    #[Url]
    public string $status = '';

    public function mount(): void
    {
        kSetSiteTitle('marketing', 'campaigns');
        $this->setPageGate('marketing.campaigns');
    }

    protected function tableColumns(): array
    {
        return [
            'name' => $this->columnMaker('Campaign', locked: true, sortable: true),
            'template' => $this->columnMaker('Template'),
            'recipients' => $this->columnMaker('Recipients'),
            'status' => $this->columnMaker('Status', sortable: true),
            'created_at' => $this->columnMaker('Created', sortable: true),
            'send_date' => $this->columnMaker('Scheduled / Sent'),
        ];
    }

    protected function tableQuery(): Builder
    {
        return EmailCampaign::query()
            ->with('emailTemplate')
            ->when($this->search, fn (Builder $query) => $query->searchMacro(['name', 'subject'], $this->search))
            ->when($this->status !== '', fn (Builder $query) => $query->where('status', (int) $this->status));
    }

    protected function tableSubject(): string
    {
        return 'campaigns';
    }

    protected function tableFilters(): array
    {
        return [
            'search' => $this->filterMaker('Search'),
            'status' => $this->filterMaker('Status', StatusEmailCampaign::forSelect()),
        ];
    }

    protected function tableDeletable(): bool
    {
        return true;
    }

    protected function tableDeleteAction(): ActivityActionEnum
    {
        return ActivityActionEnum::EMAIL_CAMPAIGN_DELETE;
    }

    protected function tableDeleteBlocked(Model $item): ?string
    {
        /** @var EmailCampaign $item */
        return $item->status->isSending() ? "\"{$item->name}\" is currently sending." : null;
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function campaigns()
    {
        return $this->applySort($this->tableQuery(), 'created_at')->paginate($this->tablePerPage());
    }

    protected function tableRows(): iterable
    {
        return $this->campaigns;
    }

    #[Computed]
    public function metrics(): array
    {
        return [
            $this->metricMaker('Active campaigns', EmailCampaign::query()->whereIn('status', [StatusEmailCampaign::DRAFT, StatusEmailCampaign::SCHEDULED])->count(), 'megaphone', tone: 'lime'),
            $this->metricMaker('Scheduled', EmailCampaign::query()->where('status', StatusEmailCampaign::SCHEDULED)->count(), 'clock', tone: 'sky'),
            $this->metricMaker('Sent (30d)', EmailCampaign::query()->sent()->where('sent_at', '>=', now()->subDays(30))->count(), 'paper-airplane', tone: 'emerald'),
            $this->metricMaker('Failed', EmailCampaign::query()->where('status', StatusEmailCampaign::FAILED)->count(), 'exclamation-triangle', tone: 'rose'),
        ];
    }

    public function duplicate(EmailCampaign $campaign): void
    {
        $this->checkGate(GateAccessEnum::CREATE);

        $copy = app(EmailTemplateService::class)->createCampaignFromTemplate($campaign->emailTemplate, [
            'name' => "{$campaign->name} (Copy)",
            'subject' => $campaign->subject,
            'preview_text' => $campaign->preview_text,
        ]);

        // The duplicate keeps the source's own content/design, not the template's —
        // a campaign edited well past its template is duplicated as it stands.
        $copy->fill([
            'content' => $campaign->content,
            'design' => $campaign->design,
            'footer_section_id' => $campaign->footer_section_id,
        ])->save();

        $this->redirectRoute('admin.marketing.campaigns.edit', $copy, navigate: true);
    }

    public function confirmDelete(EmailCampaign $campaign): void
    {
        $this->checkGate(GateAccessEnum::FULL, 'You do not have delete access to campaigns.');

        $this->respondError($this->tableDeleteBlocked($campaign) ?? '', (bool) $this->tableDeleteBlocked($campaign));

        $this->campaign = $campaign;

        Flux::modal('deleteCampaignModal')->show();
    }

    public function delete(): bool
    {
        $this->checkGate(GateAccessEnum::FULL, 'You do not have delete access to campaigns.');

        $this->respondError('Select a campaign to delete first.', ! $this->campaign);
        $this->respondError($this->tableDeleteBlocked($this->campaign) ?? '', (bool) $this->tableDeleteBlocked($this->campaign));

        $description = " campaign: {$this->campaign->name}";

        $this->campaign->delete();

        app(ActivityLogService::class)->logActivity(ActivityActionEnum::EMAIL_CAMPAIGN_DELETE, $description);

        Flux::modal('deleteCampaignModal')->close();
        $this->reset('campaign');
        unset($this->campaigns, $this->metrics);

        return $this->respondSuccess('The campaign has been deleted.');
    }
};
?>

<div class="space-y-6">
    <x-dashboard.page-header icon="megaphone" subtitle="Design templates, build campaigns, and send to your audience.">
        <x-slot:actions>
            <x-dashboard.gate.button gate="marketing.campaigns" level="create" href="{{ route('admin.marketing.campaigns.create') }}" wire:navigate variant="primary" icon="plus">
                Create Email
            </x-dashboard.gate.button>
        </x-slot:actions>
    </x-dashboard.page-header>

    <x-marketing.tabs active="campaigns" />

    <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4" aria-label="Campaign metrics">
        @foreach ($this->metrics as $metric)
            <x-dashboard.stat-card :metric="$metric" />
        @endforeach
    </section>

    <flux:card class="space-y-5">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <flux:heading level="2" size="lg">Campaigns</flux:heading>
                <flux:text class="mt-1">Every email you have drafted, scheduled, or sent.</flux:text>
            </div>

            <div class="flex flex-col gap-3 sm:flex-row">
                <flux:input class="sm:min-w-60" wire:model.live.debounce.350ms="search" placeholder="Search campaigns" icon="magnifying-glass" />
                <flux:select wire:model.live="status" class="sm:min-w-40">
                    <flux:select.option value="">All statuses</flux:select.option>
                    @foreach (StatusEmailCampaign::forSelect() as $value => $label)
                        <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
        </div>

        @if ($this->campaigns->isEmpty())
            <x-dashboard.workspace-no-record
                icon="megaphone"
                label="No campaigns yet"
                text="Create your first email and it will show up here — draft, scheduled or sent."
            />
        @else
            <x-table.active-filters :filters="$this->tableActiveFilters" />

            <flux:table :paginate="$this->campaigns">
                <x-table.columns :columns="$this->tableColumnList" :sort="$sortColumn" :direction="$sortDirection" actions actions-label="" />

                <x-table.rows :columns="$this->tableColumnList">
                    @forelse ($this->campaigns as $item)
                        <flux:table.row wire:key="campaign-{{ $item->id }}">
                            <x-table.cell column="name">
                                <div class="font-medium text-slate-950 dark:text-white">{{ $item->name }}</div>
                                <div class="text-xs text-slate-500 dark:text-slate-400">{{ $item->subject }}</div>
                            </x-table.cell>

                            <x-table.cell column="template">
                                {{ $item->emailTemplate?->name ?? 'Blank' }}
                            </x-table.cell>

                            <x-table.cell column="recipients">
                                {{ $item->estimated_recipients !== null ? number_format($item->estimated_recipients) : '—' }}
                            </x-table.cell>

                            <x-table.cell column="status">
                                <x-util.e-badge :enum="$item->status" />
                            </x-table.cell>

                            <x-table.cell column="created_at">{{ $item->createdAtHuman() ?? $item->created_at->diffForHumans() }}</x-table.cell>

                            <x-table.cell column="send_date">
                                {{ $item->sent_at?->format('M j, Y') ?? $item->scheduled_at?->format('M j, Y g:i A') ?? '—' }}
                            </x-table.cell>

                            <x-table.cell>
                                <flux:dropdown position="bottom" align="end">
                                    <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" />
                                    <flux:menu>
                                        <x-dashboard.gate.menu-item
                                            gate="marketing.campaigns"
                                            level="modify"
                                            icon="pencil-square"
                                            href="{{ $item->status->isEditable() ? route('admin.marketing.campaigns.edit', $item) : route('admin.marketing.sent.show', $item) }}"
                                            wire:navigate
                                        >
                                            {{ $item->status->isEditable() ? 'Edit' : 'View' }}
                                        </x-dashboard.gate.menu-item>
                                        <x-dashboard.gate.menu-item gate="marketing.campaigns" level="create" icon="document-duplicate" wire:click="duplicate({{ $item->id }})">
                                            Duplicate
                                        </x-dashboard.gate.menu-item>
                                        <flux:menu.separator />
                                        <x-dashboard.gate.menu-item gate="marketing.campaigns" level="full" icon="trash" variant="danger" wire:click="confirmDelete({{ $item->id }})">
                                            Delete
                                        </x-dashboard.gate.menu-item>
                                    </flux:menu>
                                </flux:dropdown>
                            </x-table.cell>
                        </flux:table.row>
                    @empty
                        <x-table.empty :columns="$this->tableColumnList" actions label="No campaigns" icon="megaphone" text="No campaigns match the current filters." />
                    @endforelse
                </x-table.rows>
            </flux:table>
        @endif
    </flux:card>

    <x-dashboard.confirm-modal name="deleteCampaignModal" title="Delete this campaign?" icon="trash" confirm="Delete it" cancel="Keep it" wire:click="delete">
        "{{ $this->campaign?->name }}" and its send history will be permanently removed. This can't be undone.
    </x-dashboard.confirm-modal>
</div>
