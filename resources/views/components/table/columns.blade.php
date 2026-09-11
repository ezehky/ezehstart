{{--
    The header row, built from the columns the page declared rather than written out
    again in markup.

        <x-table.columns :columns="$this->tableColumnList" :sort="$sortColumn" :direction="$sortDirection" selectable actions />

    A column the account has put away is simply not here, which is what keeps the
    header and the body in step — both read the same list. `selectable` adds the
    checkbox column in front and `actions` the empty one at the end.

    A sortable header sorts on click; the arrow is Flux's own, so it looks like every
    other sortable table.
--}}

@props([
    'columns' => [],
    'sort' => '',
    'direction' => 'desc',
    'selectable' => false,
    'actions' => false,
    'actionsLabel' => 'Actions',
    'selectAllModel' => 'selectPage',
    'sortAction' => 'sortBy',
])

@php
    // Carried as a bag rather than written inline: a bare @if cannot live in a
    // component tag's attribute list — Blade's component parser reads it as an
    // attribute called "@if" and prints it into the page.
    $sortAttributes = fn (string $key, bool $sortable) => new \Illuminate\View\ComponentAttributeBag(
        $sortable ? ['wire:click' => "{$sortAction}('{$key}')"] : []
    );
@endphp

<flux:table.columns {{ $attributes->merge() }}>
    @if ($selectable)
        <flux:table.column class="w-10">
            <flux:checkbox
                wire:model.live="{{ $selectAllModel }}"
                aria-label="Select every row on this page"
            />
        </flux:table.column>
    @endif

    @foreach ($columns as $key => $column)
        @continue (! $column['visible'])

        <flux:table.column
            :sortable="$column['sortable']"
            :sorted="$sort === $key"
            :direction="$direction"
            :attributes="$sortAttributes($key, $column['sortable'])"
        >
            {{ $column['label'] }}
        </flux:table.column>
    @endforeach

    @if ($actions)
        <flux:table.column>{{ $actionsLabel }}</flux:table.column>
    @endif
</flux:table.columns>
