{{--
    A date field with a calendar, for the Flux tier that has none.

        <x-form.date-field wire:model="published_at" label="Publish on" />
        <x-form.date-field wire:model.live="joinedFrom" label="Joined after" :min="now()->subYear()" />
        <x-form.date-field mode="range" wire:model.live="dateFrom" end-model="dateTo" label="Order date" />

    The value is a plain YYYY-MM-DD string, which is what makes a date filter read
    like every other filter in the project:

        ->when($this->dateFrom !== '', fn ($query) => $query->whereDate('created_at', '>=', $this->dateFrom))

    In range mode the same wire:model modifiers are applied to both ends, so
    `wire:model.live` on the start makes the end live too. `end-model` names the
    second property; without it a range has nowhere to put its far end.
--}}

@props([
    'mode' => 'single',
    'endModel' => null,
    'label' => null,
    'description' => null,
    'placeholder' => 'Select a date',
    'min' => null,
    'max' => null,
    'months' => null,
    'weekStart' => 0,
    'format' => 'medium',
    'clearable' => true,
])

@php
    $mode = match ($mode) {
        'single', 'range' => $mode,
        default => throw new \InvalidArgumentException("Invalid date field mode: {$mode}"),
    };

    $format = match ($format) {
        'medium', 'long', 'iso' => $format,
        default => throw new \InvalidArgumentException("Invalid date field format: {$format}"),
    };

    if ($mode === 'range' && ! $endModel) {
        throw new \InvalidArgumentException('A range date field needs an end-model to put its far end in.');
    }

    // The bound property comes in as whatever wire:model variant the caller wrote,
    // modifiers and all. Reusing the whole directive on the second input is what
    // keeps a live range from having one end that updates and one that does not.
    $modelDirective = collect($attributes->getAttributes())
        ->keys()
        ->first(fn ($key) => str_starts_with($key, 'wire:model'));

    $errorName = $modelDirective ? $attributes->get($modelDirective) : $attributes->get('name');

    $startAttributes = new \Illuminate\View\ComponentAttributeBag(
        $modelDirective ? [$modelDirective => $attributes->get($modelDirective)] : []
    );

    $endAttributes = new \Illuminate\View\ComponentAttributeBag(
        $modelDirective && $endModel ? [$modelDirective => $endModel] : []
    );

    // Bounds are accepted as anything kDatetimeConverter understands, so a caller
    // can pass now()->subYear() without formatting it first.
    $bound = function ($value) {
        if (! $value) {
            return '';
        }

        $date = kDatetimeConverter($value);

        return $date instanceof \Illuminate\Support\Carbon ? $date->format('Y-m-d') : '';
    };

    // A range wants both ends of it visible at once; a single date does not need
    // the width.
    $months = (int) ($months ?? ($mode === 'range' ? 2 : 1));

    $picker = \Illuminate\Support\Js::from([
        'mode' => $mode,
        'min' => $bound($min),
        'max' => $bound($max),
        'weekStart' => (int) $weekStart,
        'months' => $months,
        'format' => $format,
    ]);
@endphp

<flux:field {{ $attributes->except($modelDirective ? [$modelDirective] : [])->class('w-full') }}>
    @if ($label)
        <flux:label>{{ $label }}</flux:label>
    @endif

    @if ($description)
        <flux:description>{{ $description }}</flux:description>
    @endif

    <div
        x-data="datePicker({{ $picker }})"
        x-on:keydown.escape.window="open = false"
        x-on:click.outside="open = false"
        class="relative"
    >
        {{-- Where the value actually lives. Livewire binds these, the calendar
             writes to them, and nothing else in the component is a form control. --}}
        <input type="hidden" x-ref="startInput" {{ $startAttributes }} />

        @if ($mode === 'range')
            <input type="hidden" x-ref="endInput" {{ $endAttributes }} />
        @endif

        <flux:input
            readonly
            icon="calendar"
            :placeholder="$placeholder"
            class="cursor-pointer"
            x-bind:value="label"
            x-on:click="open = ! open"
            x-on:keydown.enter.prevent="open = ! open"
        />

        @if ($clearable)
            <flux:button
                icon="x-mark"
                variant="subtle"
                size="sm"
                type="button"
                class="absolute end-1 top-1/2 -translate-y-1/2"
                x-cloak
                x-show="hasValue"
                x-on:click.stop="clear()"
                title="Clear date"
            />
        @endif

        <div
            x-cloak
            x-show="open"
            x-transition.origin.top
            class="absolute z-40 mt-2 rounded-xl border border-slate-200 bg-white p-4 shadow-lg dark:border-white/10 dark:bg-slate-900"
        >
            <div class="mb-3 flex items-center justify-between gap-2">
                <flux:button icon="chevron-left" variant="ghost" size="sm" type="button" x-on:click="shiftMonth(-1)" title="Previous month" />

                <div class="flex flex-1 justify-around gap-4">
                    <template x-for="panel in panels" :key="panel.label">
                        <span class="font-heading text-sm font-semibold text-slate-900 dark:text-white" x-text="panel.label"></span>
                    </template>
                </div>

                <flux:button icon="chevron-right" variant="ghost" size="sm" type="button" x-on:click="shiftMonth(1)" title="Next month" />
            </div>

            <div @class(['flex gap-4', 'flex-col sm:flex-row' => $months > 1])>
                <template x-for="panel in panels" :key="panel.label">
                    <div>
                        <div class="grid grid-cols-7 gap-1">
                            <template x-for="day in weekdays" :key="day">
                                <span class="grid size-9 place-items-center text-[11px] font-medium uppercase text-slate-400 dark:text-slate-500" x-text="day"></span>
                            </template>
                        </div>

                        <div class="mt-1 grid grid-cols-7 gap-1" x-on:mouseleave="hovering = null">
                            <template x-for="(cell, index) in panel.cells" :key="index">
                                <div>
                                    {{-- A blank pads the week out. It is not a button,
                                         so there is nothing there to mis-click. --}}
                                    <template x-if="! cell">
                                        <span class="block size-9"></span>
                                    </template>

                                    <template x-if="cell">
                                        <button
                                            type="button"
                                            class="grid size-9 place-items-center rounded-lg text-sm transition"
                                            x-text="cell.getDate()"
                                            x-bind:disabled="disabled(cell)"
                                            x-on:click="select(cell)"
                                            x-on:mouseenter="hovering = toISO(cell)"
                                            x-bind:class="{
                                                'bg-lime-600 font-semibold text-white dark:bg-lime-500': isSelected(cell),
                                                'bg-lime-50 dark:bg-lime-400/10': ! isSelected(cell) && inRange(cell),
                                                'font-semibold text-lime-700 dark:text-lime-300': ! isSelected(cell) && isToday(cell),
                                                'cursor-not-allowed opacity-30': disabled(cell),
                                                'hover:bg-slate-100 dark:hover:bg-white/10': ! isSelected(cell) && ! disabled(cell),
                                            }"
                                        ></button>
                                    </template>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </div>

    @if ($errorName)
        <flux:error name="{{ $errorName }}" />
    @endif
</flux:field>
