{{--
    A color field with a swatch trigger, a curated preset grid, and the native
    OS picker for anything the presets don't cover — for the Flux tier that ships
    nothing but a bare `<input type="color">`, whose swatch-plus-greyed-hex look
    is the browser's own chrome and cannot be restyled.

        <x-form.color-field wire:model.live="design.brand" label="Brand" />
        <x-form.color-field wire:model.live.debounce.1000ms="{{ $prefix }}.background" label="Background" clearable />

    The bound property is a plain "#rrggbb" string, or "" when `clearable` and
    nothing is chosen — same shape a native color input's `.value` would give you,
    so no caller-side conversion changes.

    The swatch button doubles as the field's leading icon and the popover's
    trigger; the text beside it stays a real, typable input, so pasting a hex
    code works exactly as it did before this component existed.
--}}

@props([
    'label' => null,
    'description' => null,
    'presets' => null,
    'clearable' => false,
    'size' => null,
])

@php
    // The bound property arrives with a modifier attached (`wire:model.live`), so it
    // cannot be read by exact key. Reused verbatim on the text input below — a
    // debounced or deferred caller stays debounced or deferred here too.
    $wireModel = $attributes->whereStartsWith('wire:model');
    $model = $wireModel->first() ?? $attributes->get('name');

    if (! $model) {
        throw new \InvalidArgumentException('x-form.color-field needs a wire:model or a name to bind to.');
    }

    $presets ??= [
        '#ef4444', '#f97316', '#f59e0b', '#eab308',
        '#84cc16', '#22c55e', '#10b981', '#14b8a6',
        '#06b6d4', '#3b82f6', '#6366f1', '#8b5cf6',
        '#d946ef', '#ec4899', '#0f172a', '#ffffff',
    ];
@endphp

<flux:field {{ $attributes->except(array_keys($wireModel->getAttributes()))->class('w-full') }}>
    @if ($label)
        <flux:label>{{ $label }}</flux:label>
    @endif

    @if ($description)
        <flux:description>{{ $description }}</flux:description>
    @endif

    <div
        class="relative"
        x-data="{
            open: false,
            {{-- `$wire.get()` reads Livewire's reactive state, so the swatch and the --}}
            {{-- popover repaint the moment the text input changes — no round trip --}}
            {{-- needed to see your own edit reflected in the preview. --}}
            get current() { return $wire.get(@js($model)) || '' },
        }"
        x-on:keydown.escape.window="open = false"
        x-on:click.outside="open = false"
    >
        <flux:input
            {{ $attributes->whereStartsWith('wire:model') }}
            maxlength="7"
            placeholder="{{ $clearable ? 'None' : '#000000' }}"
            class="font-mono lowercase"
            :size="$size"
        >
            <x-slot name="iconLeading">
                <button
                    type="button"
                    class="size-4 shrink-0 rounded ring-1 ring-inset ring-black/10 transition hover:scale-110 dark:ring-white/20"
                    x-bind:style="current ? `background-color: ${current}` : ''"
                    x-bind:class="! current && 'bg-[linear-gradient(45deg,#0000_25%,#00000022_25%,#00000022_50%,#0000_50%,#0000_75%,#00000022_75%,#00000022)] bg-size-[6px_6px]'"
                    x-on:click="open = ! open"
                    aria-label="Choose a color"
                ></button>
            </x-slot>
        </flux:input>

        <div
            x-cloak
            x-show="open"
            x-transition.origin.top
            class="absolute z-40 mt-2 w-56 rounded-xl border border-slate-200 bg-white p-3 shadow-lg dark:border-white/10 dark:bg-slate-900"
        >
            <div class="grid grid-cols-8 gap-1.5">
                @foreach ($presets as $preset)
                    <button
                        type="button"
                        class="size-5 rounded ring-1 ring-inset ring-black/10 transition hover:scale-110 dark:ring-white/20"
                        style="background-color: {{ $preset }}"
                        x-on:click="$wire.set(@js($model), @js($preset)); open = false"
                        aria-label="{{ $preset }}"
                    ></button>
                @endforeach
            </div>

            <div class="mt-3 flex items-center justify-between gap-2 border-t border-slate-100 pt-3 dark:border-white/10">
                <label class="flex cursor-pointer items-center gap-1.5 text-xs font-medium text-slate-600 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white">
                    <flux:icon name="eye-dropper" class="size-3.5" />
                    Custom
                    {{-- The native picker stays reachable for anything the preset grid --}}
                    {{-- doesn't cover — an eyedropper pick, or an exact brand value. --}}
                    <input
                        type="color"
                        class="sr-only"
                        x-bind:value="current || '#000000'"
                        x-on:input="$wire.set(@js($model), $event.target.value)"
                    />
                </label>

                @if ($clearable)
                    <button
                        type="button"
                        class="text-xs font-medium text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-white"
                        x-on:click="$wire.set(@js($model), ''); open = false"
                    >
                        Clear
                    </button>
                @endif
            </div>
        </div>
    </div>

    <flux:error name="{{ $model }}" />
</flux:field>
