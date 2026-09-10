@props([
    'tags' => [],
    'label' => 'Tags',
    'action' => 'addTag',
    'input' => 'new_tag',
    'placeholder' => 'Add a tag…',
    'description' => 'Tick the ones that fit, or type a new one.',
    'limit' => 12,
])

@php
    // The bound property may carry a modifier (`wire:model.live`), so it cannot be
    // read by exact key. It names both the chip binding and the error message.
    $model = $attributes->whereStartsWith('wire:model')->first();

    // A library of two hundred tags would otherwise push the publish controls off
    // the screen. The caller's list already leads with whatever is ticked, so the
    // hidden tail is only ever tags this record does not carry.
    $overflow = max(count($tags) - $limit, 0);
@endphp

<flux:card class="space-y-3" x-data="{ expanded: false }">
    <flux:heading level="2" size="sm">{{ $label }}</flux:heading>

    @if (count($tags))
        {{-- The same peer-checked chip the icon picker uses. A native checkbox keeps --}}
        {{-- arrow-key navigation and the browser's own focus ring, and the array --}}
        {{-- binding is Livewire's, so ticking one costs no request. --}}
        <div class="flex flex-wrap gap-2">
            @foreach ($tags as $index => $tag)
                <label
                    wire:key="tag-{{ kSlug($tag) }}"
                    class="cursor-pointer"
                    @if ($index >= $limit) x-cloak x-show="expanded" @endif
                >
                    <input
                        type="checkbox"
                        value="{{ $tag }}"
                        class="peer sr-only"
                        wire:model="{{ $model }}"
                    />

                    <span class="inline-block rounded-full border border-slate-200 px-3 py-1 text-sm text-slate-600 transition hover:bg-slate-50 peer-checked:border-accent peer-checked:bg-lime-100 peer-checked:text-lime-700 peer-focus-visible:ring-2 peer-focus-visible:ring-accent dark:border-white/10 dark:text-slate-300 dark:hover:bg-white/5 dark:peer-checked:bg-lime-400/10 dark:peer-checked:text-lime-300">
                        {{ $tag }}
                    </span>
                </label>
            @endforeach
        </div>

        @if ($overflow)
            <flux:button size="sm" variant="ghost" type="button" x-on:click="expanded = ! expanded">
                <span x-show="! expanded">Show {{ $overflow }} more</span>
                <span x-cloak x-show="expanded">Show less</span>
            </flux:button>
        @endif
    @endif

    {{-- Enter is how a tag box is expected to behave; the button is there for the --}}
    {{-- touch keyboards that have no visible one. --}}
    <div class="flex items-start gap-2">
        <flux:input
            class="flex-1"
            wire:model="{{ $input }}"
            wire:keydown.enter.prevent="{{ $action }}"
            :placeholder="$placeholder"
        />

        <flux:button type="button" icon="plus" wire:click="{{ $action }}" title="Add tag" />
    </div>

    <flux:description>{{ $description }}</flux:description>

    @if ($model)
        <flux:error name="{{ $model }}" />
    @endif

    <flux:error name="{{ $input }}" />
</flux:card>
