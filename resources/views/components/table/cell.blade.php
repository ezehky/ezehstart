{{--
    A cell that is only there while its column is.

        <x-table.cell column="reference" class="font-medium">{{ $item->reference }}</x-table.cell>
        <x-table.cell column="amount">{!! $item->amountMoney() !!}</x-table.cell>
        <x-table.cell column="user" :href="route('admin.user', $item->user)">{{ $item->user?->name }}</x-table.cell>

    The column list comes from the surrounding <x-table.rows> rather than from the
    caller, so a row reads as a row rather than as fifteen repetitions of the same
    argument. A cell whose column the account has put away renders nothing at all —
    not an empty <td>, which would leave the row one cell wider than the header.

    A cell with no `column` is always shown; that is the one at the end holding the
    actions, which is not a column anybody can hide.

    With `href`, the whole cell becomes a link to the record. It navigates the Livewire
    way unless told otherwise, so a click does not cost a full page load.
--}}

@aware(['columns' => []])

@props([
    'column' => null,
    'href' => null,
    'navigate' => true,
])

@if ($column === null || data_get($columns, "{$column}.visible", true))
    <flux:table.cell {{ $attributes->merge() }}>
        @if ($href)
            <a
                href="{{ $href }}"
                @if ($navigate) wire:navigate @endif
                class="block underline-offset-4 dark:decoration-slate-600"
            >
                {{ $slot }}
            </a>
        @else
            {{ $slot }}
        @endif
    </flux:table.cell>
@endif
