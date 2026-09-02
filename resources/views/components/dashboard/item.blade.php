@props(['label', 'value' => null])
<div>
    <p class="text-xs text-slate-500 dark:text-slate-400">{!! $label !!}</p>
    <p class="mt-0.5 text-sm font-semibold text-slate-950 dark:text-white">{!! $value ?: '-' !!}</p>
</div>
