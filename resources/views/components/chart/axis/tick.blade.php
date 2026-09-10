@aware(['axis'])

{{-- <text> inherits fill, not color, so the tone here is a fill-* rather than a text-*. --}}
<g {{ $attributes->class('fill-slate-500 text-xs dark:fill-slate-400') }} x-html="renderTicks(@js($axis))"></g>
