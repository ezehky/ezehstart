<div
    x-show="hovering"
    x-transition.opacity.duration.100ms
    :style="tooltipStyle"
    {{ $attributes->class('pointer-events-none absolute z-10 -mt-2 -translate-x-1/2 -translate-y-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs shadow-lg dark:border-slate-700 dark:bg-slate-900') }}
>
    {{ $slot }}
</div>
