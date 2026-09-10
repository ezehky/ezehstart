@props([
    'categories',
    'label' => 'Categories',
    'empty' => 'No categories yet.',
    'emptyHref' => null,
    'emptyAction' => 'Add one',
])

@php
    // The bound property may carry a modifier (`wire:model.live`), so it cannot be
    // read by exact key. It names both the checkbox binding and the error message.
    $model = $attributes->whereStartsWith('wire:model')->first();
@endphp

<flux:card class="space-y-3">
    <flux:heading level="2" size="sm">{{ $label }}</flux:heading>

    @forelse ($categories as $category)
        <flux:checkbox
            wire:key="category-{{ $category->id }}"
            wire:model="{{ $model }}"
            value="{{ $category->id }}"
            :label="$category->name"
        />
    @empty
        <flux:text size="sm">
            {{ $empty }}
            @if ($emptyHref)
                <flux:link href="{{ $emptyHref }}">{{ $emptyAction }}</flux:link>.
            @endif
        </flux:text>
    @endforelse

    @if ($model)
        <flux:error name="{{ $model }}" />
    @endif
</flux:card>
