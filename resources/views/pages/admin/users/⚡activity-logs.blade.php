<?php

use App\Enums\ActivityActionEnum;
use App\Models\ActivityLog;
use App\Traits\WithGateProps;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithGateProps, WithPagination;

    public ?int $selectedLogId = null;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $action = '';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    public function mount(): void
    {
        kSetSiteTitle('users', 'activity-logs');
        $this->setPageGate('users.activity-logs');
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedAction(): void
    {
        $this->resetPage();
    }

    public function updatedFrom(): void
    {
        $this->resetPage();
    }

    public function updatedTo(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function logs()
    {
        return ActivityLog::query()
            ->with('user:id,name,email,avatar')
            ->when($this->search !== '', fn ($query) => $query
                ->where(fn ($inner) => $inner
                    ->where('description', 'like', "%{$this->search}%")
                    ->orWhere('ip_address', 'like', "%{$this->search}%")
                    ->orWhereHas('user', fn ($user) => $user->searchMacro(['name', 'email'], $this->search))))
            ->when($this->action !== '', fn ($query) => $query->where('activity_log_action', $this->action))
            ->when($this->from !== '', fn ($query) => $query->whereDate('created_at', '>=', $this->from))
            ->when($this->to !== '', fn ($query) => $query->whereDate('created_at', '<=', $this->to))
            ->latest()
            ->paginate(20);
    }

    #[Computed]
    public function selectedLog(): ?ActivityLog
    {
        return $this->selectedLogId
            ? ActivityLog::query()->with('user:id,name,email,avatar')->find($this->selectedLogId)
            : null;
    }

    #[Computed]
    public function actionOptions(): array
    {
        return ActivityActionEnum::forSelect();
    }

    public function show(int $logId): void
    {
        $this->selectedLogId = $logId;

        unset($this->selectedLog);

        Flux::modal('activityLogModal')->show();
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'action', 'from', 'to');
        $this->resetPage();
    }

    /**
     * The before/after pairs for a log, keyed by column.
     *
     * @return array<string, array{original: mixed, change: mixed}>
     */
    public function diff(ActivityLog $log): array
    {
        $original = (array) ($log->original ?? []);
        $changes = (array) ($log->changes ?? []);

        return collect(array_keys($original + $changes))
            ->mapWithKeys(fn ($column) => [$column => [
                'original' => data_get($original, $column),
                'change' => data_get($changes, $column),
            ]])
            ->all();
    }
};
?>

<div class="space-y-6">
    <flux:card class="space-y-5">
        <div class="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
            <div>
                <flux:heading level="2" size="lg">Activity logs</flux:heading>
                <flux:text class="mt-1">Every audited action taken across the platform, by every user.</flux:text>
            </div>

            <div class="flex flex-col gap-3 sm:flex-row">
                <flux:input
                    class="sm:min-w-60"
                    wire:model.live.debounce.350ms="search"
                    placeholder="Search user, description or IP"
                    icon="magnifying-glass"
                />
                <flux:select wire:model.live="action">
                    <option value="">All actions</option>
                    @foreach ($this->actionOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </flux:select>
            </div>
        </div>

        <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
            <flux:input type="date" label="From" wire:model.live="from" />
            <flux:input type="date" label="To" wire:model.live="to" />
            <flux:button variant="ghost" icon="x-mark" wire:click="clearFilters">Clear filters</flux:button>
        </div>

        <flux:table :paginate="$this->logs">
            <flux:table.columns>
                <flux:table.column>User</flux:table.column>
                <flux:table.column>Action</flux:table.column>
                <flux:table.column>Description</flux:table.column>
                <flux:table.column>Origin</flux:table.column>
                <flux:table.column>When</flux:table.column>
                <flux:table.column>Details</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($this->logs as $item)
                    <flux:table.row wire:key="activity-{{ $item->id }}">
                        <flux:table.cell>
                            @if ($item->user)
                                <div class="flex items-center gap-3">
                                    <x-dashboard.avatar :user="$item->user" />
                                    <div class="min-w-0">
                                        <a
                                            href="{{ route('admin.user', $item->user) }}"
                                            wire:navigate
                                            class="font-medium hover:underline"
                                        >
                                            {{ $item->user->name }}
                                        </a>
                                        <div class="text-xs text-slate-500">{{ $item->user->email }}</div>
                                    </div>
                                </div>
                            @else
                                <span class="text-slate-400">Deleted user</span>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:badge size="sm">{{ $item->activity_log_action->label() }}</flux:badge>
                        </flux:table.cell>
                        <flux:table.cell class="max-w-sm truncate">{{ $item->description }}</flux:table.cell>
                        <flux:table.cell class="text-xs text-slate-500">
                            <div>{{ $item->browser ?: 'Unknown browser' }} · {{ $item->os ?: 'Unknown OS' }}</div>
                            <div class="font-mono">{{ $item->ip_address }}</div>
                        </flux:table.cell>
                        <flux:table.cell>{{ $item->createdAtDatetimeHuman() }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:button
                                icon="eye"
                                variant="ghost"
                                size="sm"
                                wire:click="show({{ $item->id }})"
                                title="View log entry"
                            />
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="6">
                            <x-dashboard.workspace-no-record
                                label="Activity logs"
                                icon="clock"
                                text="No activity matches the current filters."
                            />
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </flux:card>

    <flux:modal name="activityLogModal" class="md:w-175">
        @if ($log = $this->selectedLog)
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ $log->activity_log_action->label() }}</flux:heading>
                    <flux:text class="mt-1">{{ $log->description }}</flux:text>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-dashboard.mini-stat :value="$log->user?->name ?? 'Deleted user'" label="Performed by" />
                    <x-dashboard.mini-stat :value="$log->createdAtDatetimeHuman()" label="Recorded at" />
                    <x-dashboard.mini-stat :value="$log->ip_address" label="IP address" />
                    <x-dashboard.mini-stat
                        :value="$log->platform->label().' · '.($log->device_type ?: 'Unknown device')"
                        label="Platform"
                    />
                </div>

                @php($diff = $this->diff($log))

                @if ($diff)
                    <div class="space-y-2">
                        <flux:heading size="sm">Changed fields</flux:heading>
                        <flux:table>
                            <flux:table.columns>
                                <flux:table.column>Field</flux:table.column>
                                <flux:table.column>Before</flux:table.column>
                                <flux:table.column>After</flux:table.column>
                            </flux:table.columns>
                            <flux:table.rows>
                                @foreach ($diff as $column => $values)
                                    <flux:table.row wire:key="diff-{{ $log->id }}-{{ $column }}">
                                        <flux:table.cell class="font-medium">{{ kBreakText($column) }}</flux:table.cell>
                                        <flux:table.cell class="max-w-xs break-words text-rose-600 dark:text-rose-400">
                                            {{ is_scalar($values['original']) ? $values['original'] : json_encode($values['original']) }}
                                        </flux:table.cell>
                                        <flux:table.cell class="max-w-xs break-words text-emerald-600 dark:text-emerald-400">
                                            {{ is_scalar($values['change']) ? $values['change'] : json_encode($values['change']) }}
                                        </flux:table.cell>
                                    </flux:table.row>
                                @endforeach
                            </flux:table.rows>
                        </flux:table>
                    </div>
                @else
                    <x-dashboard.workspace-no-record
                        label="No field changes"
                        icon="document-text"
                        text="This entry records an action rather than a data change."
                        border
                    />
                @endif

                <div class="flex justify-end">
                    <flux:modal.close>
                        <flux:button variant="ghost">Close</flux:button>
                    </flux:modal.close>
                </div>
            </div>
        @endif
    </flux:modal>
</div>
