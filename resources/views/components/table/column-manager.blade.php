{{--
    Which columns this account keeps on screen.

        <x-table.column-manager :columns="$this->tableColumnList" />

    Each switch saves as it is thrown — there is no Save button, because an arrangement
    that needed one is an arrangement nobody keeps. What is saved is the list of
    columns put *away*, so a column added to this screen next month appears for
    everybody rather than staying hidden from the people who use it most.

    A locked column — the one that says which row this is — has no switch. Hiding it
    would leave a table of attributes belonging to nothing.
--}}

@props([
    'columns' => [],
    'label' => 'Columns',
])

@php
    $hidden = collect($columns)->reject(fn (array $column) => $column['visible'])->count();
@endphp

<flux:dropdown position="bottom" align="end" {{ $attributes->merge() }}>
    <flux:button icon="view-columns" variant="filled">
        {{-- <span class="sr-only">{{ $label }}</span>

        @if ($hidden > 0)
            <flux:badge size="sm" color="zinc" class="ms-1 sr-only">{{ $hidden }} off</flux:badge>
        @endif --}}
    </flux:button>

    <flux:menu class="min-w-56">
        <flux:menu.group heading="Show on this screen">
            @foreach ($columns as $key => $column)
                <flux:menu.item wire:key="column-{{ $key }}">
                    @if ($column['locked'])
                        <flux:switch checked disabled :label="$column['label']" align="left" />
                    @else
                        <flux:switch
                            :checked="$column['visible']"
                            :label="$column['label']"
                            align="left"
                            wire:click="toggleColumn('{{ $key }}')"
                            wire:target="toggleColumn"
                            wire:loading.attr="disabled"
                        />
                    @endif
                </flux:menu.item>
            @endforeach
        </flux:menu.group>

        @if ($hidden > 0)
            <flux:menu.item icon="arrow-path" wire:click="resetColumns">Show them all</flux:menu.item>
        @endif
    </flux:menu>
</flux:dropdown>
