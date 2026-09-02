@props(['value', 'label'])

<div {{ $attributes->class(['rounded-xl bg-slate-50 p-4 dark:bg-slate-800/50']) }}>
    <p class="font-heading text-xl font-bold text-slate-950 dark:text-white">{{ $value }}</p>
    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $label }}</p>
</div>
