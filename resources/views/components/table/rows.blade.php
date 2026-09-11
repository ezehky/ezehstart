{{--
    The table body, and the one place the column list is handed down.

        <x-table.rows :columns="$this->tableColumnList">
            @forelse ($this->transactions as $item)
                <flux:table.row wire:key="transaction-{{ $item->id }}">
                    <x-table.cell column="reference">{{ $item->reference }}</x-table.cell>
                    …
                </flux:table.row>
            @empty
                …
            @endforelse
        </x-table.rows>

    Every <x-table.cell> inside reads the list from here with @aware, so a cell knows
    whether its own column is on screen without the page passing the list to each one.
    That is the whole reason this wrapper exists — a bare <flux:table.rows> would leave
    fifteen cells each repeating :columns="$this->tableColumnList".
--}}

@props(['columns' => []])

<flux:table.rows {{ $attributes->merge() }}>
    {{ $slot }}
</flux:table.rows>
