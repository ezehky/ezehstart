{{--
    The checkbox at the head of a row.

        <x-table.select :id="$item->id" />

    Bound to the `selected` array WithDataTable owns, so Livewire keeps the tick in
    place across a re-render without the page tracking anything itself. The value is a
    string because that is what a checkbox posts back — an int here would tick on and
    never tick off.
--}}

@props([
    'id',
    'model' => 'selected',
    'label' => 'Select this row',
])

<flux:table.cell class="w-10">
    <flux:checkbox
        wire:model.live="{{ $model }}"
        value="{{ $id }}"
        :aria-label="$label"
        {{ $attributes->merge() }}
    />
</flux:table.cell>
