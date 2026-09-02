@props(['label', 'value', 'icon', 'change' => null, 'tone' => 'slate'])

<flux:card>
    <div class="flex items-start justify-between gap-4">
        <div>
            <p class="text-sm font-medium text-slate-500 dark:text-slate-400">{!! $label !!}</p>
            <p class="mt-2 font-heading text-2xl font-bold tracking-tight text-slate-950 dark:text-white">
                {!! $value !!}
            </p>
        </div>
        <span @class([
            'grid size-10 place-items-center rounded-lg',
            'bg-emerald-50 text-emerald-700 dark:bg-emerald-400/10 dark:text-emerald-300' => $tone === 'emerald',
            'bg-amber-50 text-amber-700 dark:bg-amber-400/10 dark:text-amber-300' => $tone === 'amber',
            'bg-sky-50 text-sky-700 dark:bg-sky-400/10 dark:text-sky-300' => $tone === 'sky',
            'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200' => $tone === 'slate',
        ])>
            <flux:icon :name="$icon" class="size-5" />
        </span>
    </div>
    @if ($change)
        <p class="mt-5 text-xs font-medium text-slate-500 dark:text-slate-400">{!! $change !!}</p>
    @endif
</flux:card>
