{{--
    A number that counts up when it scrolls into view — a stats row, a "members
    so far" figure.

        <x-util.counter :to="12500" plus label="Members" />
        <x-util.counter :to="98.6" :decimals="1" suffix="%" replay="always" />
        <x-util.counter :to="$total" variant="static" prefix="$" />
        <x-util.counter :to="256" icon="hand-thumb-up" align="center" label="Happy customers" />

    variant  dynamic counts up from `from`; static prints the final number and
             boots nothing.
    replay   once counts on the first time it is seen; always counts again every
             time it comes back into view.
    plus     appends a "+" — the figure is a floor, not an exact count.
    icon     any Heroicon name, drawn above the figure. icon-style boxed sits it on
             a tinted tile (x-dashboard.icon-box); plain draws the outline alone.
             icon-tone colours it apart from the figure, lime unless told.
    align    start or center — center for a row of counters under a heading.

    The markup carries the final number, so a crawler, a screenshot or a browser
    without JavaScript reads the real figure. A screen reader is handed that same
    final figure once, rather than every frame of the count.
--}}

@props([
    'to',
    'from' => 0,
    'variant' => 'dynamic',
    'replay' => 'once',
    'duration' => 2000,
    'decimals' => 0,
    'plus' => false,
    'prefix' => null,
    'suffix' => null,
    'label' => null,
    'icon' => null,
    'iconStyle' => 'boxed',
    'iconTone' => 'lime',
    'align' => 'start',
    'size' => 'md',
    'tone' => 'slate',
])

@php
    if (! is_numeric($to) || ! is_numeric($from)) {
        throw new \InvalidArgumentException('A counter counts between two numbers.');
    }

    $variant = match ($variant) {
        'dynamic', 'static' => $variant,
        default => throw new \InvalidArgumentException("Invalid counter variant: {$variant}"),
    };

    $replay = match ($replay) {
        'once', 'always' => $replay,
        default => throw new \InvalidArgumentException("Invalid counter replay: {$replay}"),
    };

    $align = match ($align) {
        'start' => 'items-start text-left',
        'center' => 'items-center text-center',
        default => throw new \InvalidArgumentException("Invalid counter align: {$align}"),
    };

    $iconStyle = match ($iconStyle) {
        'boxed', 'plain' => $iconStyle,
        default => throw new \InvalidArgumentException("Invalid counter icon style: {$iconStyle}"),
    };

    $sizes = match ($size) {
        'sm' => ['number' => 'text-2xl', 'label' => 'text-xs', 'icon' => 'size-6', 'box' => 'xs'],
        'md' => ['number' => 'text-4xl', 'label' => 'text-sm', 'icon' => 'size-8', 'box' => 'sm'],
        'lg' => ['number' => 'text-6xl', 'label' => 'text-base', 'icon' => 'size-10', 'box' => 'md'],
        default => throw new \InvalidArgumentException("Invalid counter size: {$size}"),
    };

    // Slate is the heading colour rather than the palette's muted grey, as on the
    // countdown — a figure this large wants the contrast.
    $toneText = match ($tone) {
        'sky' => 'text-sky-700 dark:text-sky-300',
        'amber' => 'text-amber-600 dark:text-amber-300',
        'rose' => 'text-rose-600 dark:text-rose-300',
        'emerald' => 'text-emerald-700 dark:text-emerald-300',
        'slate' => 'text-slate-900 dark:text-white',
        default => 'text-lime-700 dark:text-lime-300',
    };

    $iconText = match ($iconTone) {
        'sky' => 'text-sky-700 dark:text-sky-300',
        'amber' => 'text-amber-600 dark:text-amber-300',
        'rose' => 'text-rose-600 dark:text-rose-300',
        'emerald' => 'text-emerald-700 dark:text-emerald-300',
        'slate' => 'text-slate-900 dark:text-white',
        default => 'text-lime-700 dark:text-lime-300',
    };

    $decimals = max(0, (int) $decimals);
    $final = number_format((float) $to, $decimals);
    $after = ($plus ? '+' : '').$suffix;
@endphp

<div {{ $attributes->class(['inline-flex flex-col', $align]) }}>
    @if ($icon && $iconStyle === 'boxed')
        <x-dashboard.icon-box :size="$sizes['box']" :icon="$icon" :tone="$iconTone" class="mb-4" />
    @elseif ($icon)
        <flux:icon :name="$icon" variant="outline" class="mb-3 {{ $sizes['icon'] }} {{ $iconText }}" />
    @endif

    <span class="font-heading font-bold leading-none tabular-nums {{ $sizes['number'] }} {{ $toneText }}">
        <span class="sr-only">{{ $prefix }}{{ $final }}{{ $after }}</span>

        <span aria-hidden="true">{{ $prefix }}@if ($variant === 'dynamic')<span
            x-data="counter({ from: @js((float) $from), to: @js((float) $to), duration: @js((int) $duration), decimals: @js($decimals), replay: @js($replay) })"
            x-intersect.half="enter()"
            @if ($replay === 'always') x-intersect:leave="leave()" @endif
            class="inline-grid"
        >{{-- The final figure is laid out invisibly under the running one, so the
              box is its finished width from the first frame and nothing beside it
              shuffles along as the digits grow. --}}<span class="invisible col-start-1 row-start-1">{{ $final }}</span><span class="col-start-1 row-start-1 text-right" x-text="display">{{ $final }}</span></span>@else{{ $final }}@endif{{ $after }}</span>
    </span>

    @if ($label)
        <span class="mt-2 font-medium text-slate-500 dark:text-slate-400 {{ $sizes['label'] }}">{{ $label }}</span>
    @endif
</div>
