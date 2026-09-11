{{--
    The row that stands in for a result with nothing in it.

        @empty
            <x-table.empty
                :columns="$this->tableColumnList"
                selectable
                actions
                label="Transactions"
                icon="banknotes"
                text="No transactions match the current filters."
            />
        @endforelse

    It counts the columns itself. A hand-written colspan is right on the day it is
    written and wrong the first time somebody hides a column — and a colspan that does
    not match the header is how an empty state ends up squeezed into one cell.
--}}

@props([
    'columns' => [],
    'selectable' => false,
    'actions' => false,
    'label' => null,
    'icon' => 'folder-open',
    'text' => 'Nothing matches the current filters.',
])

@php
    $span = collect($columns)->filter(fn (array $column) => $column['visible'])->count()
        + ($selectable ? 1 : 0)
        + ($actions ? 1 : 0);
@endphp

<flux:table.row>
    <flux:table.cell :colspan="max($span, 1)">
        <x-dashboard.workspace-no-record :label="$label" :icon="$icon" :text="$text" />
    </flux:table.cell>
</flux:table.row>
