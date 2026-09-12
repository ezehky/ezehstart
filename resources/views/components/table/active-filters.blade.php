{{--
    What is currently narrowing the listing, and how to take it off.

        <x-table.active-filters :filters="$this->tableActiveFilters" />

    The chips come from the screen's own tableFilters() declaration, so a filter
    appears here by being declared once rather than by being written out again in
    markup. Nothing renders while nothing is filtering.

    Both ends of the date range are one chip: they were chosen together in one
    field, and half a range is not a filter of its own.
--}}

@props([
    'filters' => [],
    'label' => 'Active filters',
])

@if ($filters !== [])
    <div {{ $attributes->class('flex flex-wrap items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 dark:border-white/10 dark:bg-white/5')->merge() }}>
        <span class="text-sm font-medium text-slate-600 dark:text-slate-300">{{ $label }}</span>

        @foreach ($filters as $key => $filter)
            <flux:badge size="sm" color="sky" wire:key="active-filter-{{ $key }}">
                {{ $filter['label'] }}: {{ $filter['value'] }}

                <flux:badge.close wire:click="clearFilter('{{ $key }}')" />
            </flux:badge>
        @endforeach

        <flux:button
            class="ms-auto"
            size="xs"
            variant="subtle"
            icon="x-mark"
            wire:click="clearFilters"
            title="Take every filter off"
        >
            Clear all
        </flux:button>
    </div>
@endif
