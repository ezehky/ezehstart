@aware(['axis'])

<g
    x-html="renderAxisLine(@js($axis))"
    stroke-width="1"
    {{ $attributes->class('text-slate-300 dark:text-slate-600') }}
></g>
