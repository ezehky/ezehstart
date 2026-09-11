{{--
    The totals row, at the foot of the table it totals.

        <x-table.rows :columns="$this->tableColumnList">
            @forelse (…)
                …
            @empty
                …
            @endforelse

            <x-table.summary :columns="$this->tableColumnList" :summary="$this->tableSummary" selectable actions />
        </x-table.rows>

    Inside the table rather than under it, so every figure sits in the column it
    belongs to — a separate table below would size its own columns and leave the
    totals lined up with nothing.

    The figures are read from the whole filtered result rather than from the page on
    screen: a total of what page two happens to hold is not a total of anything, and a
    number an administrator might read out has to be the number for what they filtered.
--}}

@props([
    'columns' => [],
    'summary' => [],
    'selectable' => false,
    'actions' => false,
    'label' => 'Totals',
])

@if ($summary !== [])
    <flux:table.row class="bg-slate-50 dark:bg-white/5">
        @if ($selectable)
            <flux:table.cell class="w-10"></flux:table.cell>
        @endif

        @php($first = true)

        @foreach ($columns as $key => $column)
            @continue (! $column['visible'])

            <flux:table.cell class="font-medium text-slate-900 dark:text-white">
                @if (isset($summary[$key]))
                    {{ $summary[$key] }}
                @elseif ($first)
                    <span class="text-slate-500 dark:text-slate-400">{{ $label }}</span>
                @endif
            </flux:table.cell>

            @php($first = false)
        @endforeach

        @if ($actions)
            <flux:table.cell></flux:table.cell>
        @endif
    </flux:table.row>
@endif
