<?php

use App\Models\EmailCampaign;
use App\Traits\WithDataTable;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithDataTable, WithPagination;

    #[Url]
    public string $search = '';

    public function mount(): void
    {
        kSetSiteTitle('marketing', 'sent');
        $this->setPageGate('marketing.sent');
    }

    protected function tableColumns(): array
    {
        return [
            'name' => $this->columnMaker('Campaign', locked: true, sortable: true),
            'user' => $this->columnMaker('Sender'),
            'recipients' => $this->columnMaker('Recipients'),
            'delivered' => $this->columnMaker('Delivered'),
            'unsubscribed' => $this->columnMaker('Unsubs'),
            'sent_at' => $this->columnMaker('Sent', sortable: true),
        ];
    }

    protected function tableQuery(): Builder
    {
        return EmailCampaign::query()
            ->sent()
            ->with('user')
            ->withCount('recipients')
            ->when($this->search, fn (Builder $query) => $query->searchMacro(['name', 'subject'], $this->search));
    }

    protected function tableSubject(): string
    {
        return 'sent campaigns';
    }

    protected function tableFilters(): array
    {
        return ['search' => $this->filterMaker('Search')];
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function campaigns()
    {
        return $this->applySort($this->tableQuery(), 'sent_at')->paginate($this->tablePerPage());
    }

    protected function tableRows(): iterable
    {
        return $this->campaigns;
    }

    public function deliveredCount(EmailCampaign $campaign): int
    {
        $counts = $campaign->deliveryCounts();

        return $counts['SENT'] + $counts['QUEUED'];
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

    <x-marketing.tabs active="sent" />

    <flux:card class="space-y-5">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <flux:heading level="2" size="lg">Sent campaigns</flux:heading>
                <flux:text class="mt-1">Delivery counts for everything that has gone out.</flux:text>
            </div>

            <flux:input class="sm:min-w-60" wire:model.live.debounce.350ms="search" placeholder="Search campaigns" icon="magnifying-glass" />
        </div>

        @if ($this->campaigns->isEmpty())
            <x-dashboard.workspace-no-record icon="paper-airplane" label="Nothing sent yet" text="Once a campaign goes out, its delivery stats will appear here." />
        @else
            <flux:table :paginate="$this->campaigns">
                <x-table.columns :columns="$this->tableColumnList" :sort="$sortColumn" :direction="$sortDirection" actions actions-label="" />

                <x-table.rows :columns="$this->tableColumnList">
                    @forelse ($this->campaigns as $item)
                        <flux:table.row wire:key="sent-{{ $item->id }}">
                            <x-table.cell column="name">
                                <div class="font-medium text-slate-950 dark:text-white">{{ $item->name }}</div>
                                <div class="text-xs text-slate-500 dark:text-slate-400">{{ $item->subject }}</div>
                            </x-table.cell>
                            <x-table.cell column="user">{{ $item->user?->name ?? '—' }}</x-table.cell>
                            <x-table.cell column="recipients">{{ number_format($item->recipients_count) }}</x-table.cell>
                            <x-table.cell column="delivered">{{ number_format($this->deliveredCount($item)) }}</x-table.cell>
                            <x-table.cell column="unsubscribed">—</x-table.cell>
                            <x-table.cell column="sent_at">{{ $item->sent_at?->format('M j, Y') }}</x-table.cell>
                            <x-table.cell>
                                <flux:button size="sm" variant="ghost" icon="chart-bar" href="{{ route('admin.marketing.sent.show', $item) }}" wire:navigate>
                                    View
                                </flux:button>
                            </x-table.cell>
                        </flux:table.row>
                    @empty
                        <x-table.empty :columns="$this->tableColumnList" actions label="No sent campaigns" icon="paper-airplane" text="No sent campaigns match the current search." />
                    @endforelse
                </x-table.rows>
            </flux:table>
        @endif
    </flux:card>
</div>
