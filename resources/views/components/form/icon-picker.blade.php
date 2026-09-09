@props([
    'icon' => null,
    'label' => 'Icon',
    'badge' => 'required',
    'heading' => 'Choose an icon',
    'text' => 'Every icon the app can render. Click one to select it.',
    'clearable' => true,
])

@php
    // The bound property arrives with a modifier attached (`wire:model.live`), so it
    // cannot be read by exact key. It names the error message, the radio group and the
    // modal — a page carrying two pickers needs two distinct modal names.
    $wireModel = $attributes->whereStartsWith('wire:model');
    $model = $wireModel->first() ?? $attributes->get('name');
    $picker = 'icon-picker-'.kSlug($model ?? 'icon');

    $groups = kFluxIcons(grouped: true);
    $names = kFluxIcons();
@endphp

{{-- `display: contents` keeps the field and the modal siblings in the parent layout --}}
{{-- while giving both one Alpine scope, so the radios can write what the trigger reads. --}}
<div
    class="contents"
    x-data="{
        @if ($wireModel->isNotEmpty())
            {{-- `$wire.get()` reads Livewire's reactive state, so the trigger repaints --}}
            {{-- the moment a radio is checked — a deferred binding sends no request. --}}
            get selected() { return $wire.get(@js($model)) ?? '' },
        @else
            selected: @js($icon ?: ''),
        @endif
        get caption() {
            return this.selected
                ? this.selected.replace(/[-_]/g, ' ').replace(/\b\w/g, (letter) => letter.toUpperCase())
                : 'Select an icon'
        },
        {{-- Anchored on the dialog, not `$root`: the trigger renders its own `x-data`, --}}
        {{-- so `$root` inside the button is that wrapper, which holds no icons. --}}
        get glyph() {
            return document
                .querySelector(`[data-modal='{{ $picker }}'] [data-icon='${this.selected || 'squares-2x2'}']`)
                ?.outerHTML ?? ''
        },
    }"
>
    <flux:field>
        <flux:label :$badge>{{ $label }}</flux:label>

        {{-- The blade inside the two bindings is the pre-Alpine paint only; from init --}}
        {{-- on, the getters own this button, so `:$icon` matters for first render. --}}
        <div class="">
            <flux:modal.trigger name="{{ $picker }}">
                <flux:button type="button">
                    <span class="flex items-center gap-2 w-full">
                        {{-- The picker renders every icon already, so the chosen SVG is in --}}
                        {{-- the DOM — cloning it beats shipping a second copy of the set. --}}
                        <span class="[&>svg]:size-4" x-html="glyph">
                            <flux:icon :name="$icon ?: 'squares-2x2'" class="size-4" />
                        </span>

                        <span class="truncate sr-only" x-text="caption">
                            {{ $icon ? kBreakText($icon) : 'Select' }}
                        </span>
                    </span>
                </flux:button>
            </flux:modal.trigger>
        </div>

        @if ($model)
            <flux:error name="{{ $model }}" />
        @endif
    </flux:field>

    <flux:modal
        name="{{ $picker }}"
        class="md:w-176"
        x-data="{
            search: '',
            names: @js($names),
            get term() { return this.search.trim().toLowerCase() },
            get matches() { return this.names.filter((name) => name.includes(this.term)) },
        }"
    >
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $heading }}</flux:heading>
                <flux:text class="mt-1">{{ $text }}</flux:text>
            </div>

            <flux:input
                type="search"
                icon="magnifying-glass"
                placeholder="Search {{ count($names) }} icons…"
                x-model="search"
                autofocus
            />

            {{-- Radios rather than buttons: this is one choice out of many, and the native --}}
            {{-- input keeps arrow-key navigation and the browser's focus ring for free. --}}
            <div class="max-h-96 space-y-5 overflow-y-auto pe-1">
                @foreach ($groups as $group => $icons)
                    <div x-show="@js($icons).some((name) => name.includes(term))">
                        <p class="text-xs font-semibold tracking-wide text-slate-500 uppercase dark:text-slate-400">
                            {{ $group }}
                        </p>

                        <div class="mt-3 grid grid-cols-5 gap-2 sm:grid-cols-8 lg:grid-cols-10">
                            @foreach ($icons as $name)
                                <label x-show="'{{ $name }}'.includes(term)" class="cursor-pointer" title="{{ kBreakText($name) }}">
                                    <input
                                        type="radio"
                                        name="{{ $picker }}"
                                        value="{{ $name }}"
                                        class="peer sr-only"
                                        x-on:change="@if ($wireModel->isEmpty()) selected = $event.target.value; @endif $flux.modal('{{ $picker }}').close()"
                                        {{ $wireModel }}
                                    />

                                    <span class="grid aspect-square place-items-center rounded-lg border border-slate-200 text-slate-600 transition hover:bg-slate-50 peer-checked:border-accent peer-checked:bg-lime-100 peer-checked:text-lime-700 peer-focus-visible:ring-2 peer-focus-visible:ring-accent dark:border-white/10 dark:text-slate-300 dark:hover:bg-white/5 dark:peer-checked:bg-lime-400/10 dark:peer-checked:text-lime-300">
                                        {{-- `data-icon` is what the trigger clones the selected glyph from. --}}
                                        <flux:icon :name="$name" class="size-5" data-icon="{{ $name }}" />
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                @endforeach

                <p x-show="matches.length === 0" x-cloak class="py-10 text-center text-sm text-slate-500 dark:text-slate-400">
                    No icon matches “<span x-text="search"></span>”.
                </p>
            </div>

            <div class="flex justify-end gap-3">
                @if ($clearable && $model)
                    <flux:button
                        type="button"
                        variant="ghost"
                        x-on:click="$wire.set('{{ $model }}', ''); $flux.modal('{{ $picker }}').close()"
                    >
                        Clear selection
                    </flux:button>
                @endif

                <flux:modal.close>
                    <flux:button type="button" variant="filled">Done</flux:button>
                </flux:modal.close>
            </div>
        </div>
    </flux:modal>
</div>
