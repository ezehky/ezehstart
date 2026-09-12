{{--
    The bar that appears once rows are ticked: what is selected, how to widen or drop
    the selection, and what can be done with it.

        <x-table.bulk-bar
            :count="$this->selectedCount"
            :total="$this->tableTotalCount"
            :matching="$selectMatching"
            :columns="$this->tableExportOptions"
            subject="transactions"
        />

        <x-table.bulk-bar … subject="tags" gate="content.tags" deletable>
            <x-slot:actions>
                <flux:button size="sm" variant="filled" icon="check" wire:click="bulkApprove">Approve</flux:button>
            </x-slot:actions>
        </x-table.bulk-bar>

    Export is always offered — taking a copy of what you can already see is not a
    privilege — while delete appears only where the screen allows it at all and the
    account holds full access. Both re-check on the way in; this is the courtesy half.

    "Select all N" is the important one: a page checkbox ticks twenty rows, and an
    administrator clearing out a filtered result means the whole result, not the
    first page of it. The count is on the link because "all" means a different
    number on every screen, and it is the one somebody needs to see before pressing
    it rather than afterwards.
--}}

@props([
    'count' => 0,
    'total' => 0,
    'matching' => false,
    'subject' => 'records',
    'columns' => [],
    'gate' => null,
    'level' => 'full',
    'deletable' => false,
    'exportable' => true,
    'actions' => null,
])

@if ($count > 0)
    <div {{ $attributes->class('flex flex-col gap-3 rounded-xl border border-lime-200 bg-lime-50 px-4 py-3 sm:flex-row sm:items-center sm:justify-between dark:border-lime-400/20 dark:bg-lime-400/10')->merge() }}>
        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
            <span class="font-medium text-lime-900 dark:text-lime-200">
                {{ number_format($count) }} {{ $subject }} selected
            </span>

            @if (!$matching && $total > $count)
                <flux:link href="#" wire:click.prevent="selectAllMatching" class="text-sm">
                    Select all {{ number_format($total) }}
                </flux:link>
            @endif

            <flux:link href="#" wire:click.prevent="clearSelection" variant="subtle" class="text-sm">
                Deselect all
            </flux:link>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            {!! $actions !!}

            {{-- One button rather than a format menu: the format is the smallest of
                 the decisions an export involves, and it belongs beside the other
                 two — which columns go in the file, and what they are called once
                 they are in it. --}}
            @if ($exportable)
                <flux:button size="sm" variant="filled" icon="arrow-down-tray" wire:click="openExportModal">
                    Export
                </flux:button>
            @endif

            @if ($deletable)
                @if ($gate)
                    <x-dashboard.gate.button
                        :gate="$gate"
                        :level="$level"
                        size="sm"
                        variant="danger"
                        icon="trash"
                        wire:click="confirmBulkDelete"
                    >
                        Delete
                    </x-dashboard.gate.button>
                @else
                    <flux:button size="sm" variant="danger" icon="trash" wire:click="confirmBulkDelete">
                        Delete
                    </flux:button>
                @endif
            @endif
        </div>
    </div>

    {{-- The dialogs travel with the bar rather than being left for each page to
         remember. A bulk delete is the one action on a listing with no undo, and
         "the page that forgot its confirm modal" is not a failure worth allowing. --}}
    @if ($exportable)
        <x-table.export-modal :columns="$columns" :subject="$subject" :count="$count" />
    @endif

    @if ($deletable)
        <x-dashboard.confirm-modal
            name="bulkDeleteModal"
            title="Delete {{ number_format($count) }} {{ $subject }}?"
            icon="trash"
            confirm="Delete them"
            cancel="Keep them"
            wire:click="bulkDelete"
        >
            This cannot be undone, and it takes every row currently selected — not just
            the ones on this page.
        </x-dashboard.confirm-modal>
    @endif
@endif
