{{--
    A countdown to a moment in the future, ticking in the browser.

        <x-util.countdown :until="$post->published_at" units="days,hours,minutes" expire="reload" />

    The units are a display choice, not an arithmetic one: the largest one on show
    absorbs everything above it, so dropping days from a three-day countdown reads
    72 hours rather than losing two of them.

    Whatever `expire` does, the element also dispatches a bubbling
    `countdown-expired` event on the tick that crosses zero, so a page needing more
    than the three actions can listen for it:

        <div x-on:countdown-expired.window="$wire.$refresh()">
--}}

@props([
    'until',
    'units' => 'days,hours,minutes,seconds',
    'expire' => 'message',
    'message' => 'This countdown has ended.',
    'size' => 'md',
    'variant' => 'boxed',
    'tone' => 'slate',
])

@php
    // The browser does the arithmetic, so the target leaves here as an absolute
    // timestamp. A string is parsed in the app timezone — pass a Carbon wherever
    // that is not what was meant.
    $target = kDatetimeConverter($until);

    if (! $target instanceof \Illuminate\Support\Carbon) {
        throw new \InvalidArgumentException('A countdown needs a date to count to.');
    }

    $expire = match ($expire) {
        'message', 'reload', 'hide' => $expire,
        default => throw new \InvalidArgumentException("Invalid countdown expire action: {$expire}"),
    };

    $variant = match ($variant) {
        'boxed', 'plain', 'inline' => $variant,
        default => throw new \InvalidArgumentException("Invalid countdown variant: {$variant}"),
    };

    $sizes = match ($size) {
        'sm' => ['number' => 'text-lg', 'label' => 'text-[10px]', 'tile' => 'min-w-12 px-2 py-1.5', 'gap' => 'gap-1.5', 'message' => 'text-xs'],
        'md' => ['number' => 'text-2xl', 'label' => 'text-[11px]', 'tile' => 'min-w-16 px-3 py-2', 'gap' => 'gap-2', 'message' => 'text-sm'],
        'lg' => ['number' => 'text-4xl', 'label' => 'text-xs', 'tile' => 'min-w-20 px-4 py-3', 'gap' => 'gap-3', 'message' => 'text-base'],
        default => throw new \InvalidArgumentException("Invalid countdown size: {$size}"),
    };

    $toneClasses = match ($tone) {
        'sky' => 'bg-sky-50 text-sky-700 dark:bg-sky-400/10 dark:text-sky-300',
        'amber' => 'bg-amber-50 text-amber-600 dark:bg-amber-400/10 dark:text-amber-300',
        'rose' => 'bg-rose-50 text-rose-600 dark:bg-rose-400/10 dark:text-rose-300',
        'emerald' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-400/10 dark:text-emerald-300',
        'slate' => 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300',
        default => 'bg-lime-100 text-lime-700 dark:bg-lime-400/10 dark:text-lime-300',
    };

    // The untiled variants put the tone on the digits themselves, and slate there
    // is the heading colour rather than the palette's muted grey — numbers this
    // large want the contrast.
    $toneText = match ($tone) {
        'sky' => 'text-sky-700 dark:text-sky-300',
        'amber' => 'text-amber-600 dark:text-amber-300',
        'rose' => 'text-rose-600 dark:text-rose-300',
        'emerald' => 'text-emerald-700 dark:text-emerald-300',
        'slate' => 'text-slate-900 dark:text-white',
        default => 'text-lime-700 dark:text-lime-300',
    };

    $order = ['days', 'hours', 'minutes', 'seconds'];

    $asked = collect(is_array($units) ? $units : explode(',', (string) $units))
        ->map(fn ($unit) => strtolower(trim((string) $unit)))
        ->filter()
        ->unique();

    if ($asked->isEmpty() || $asked->diff($order)->isNotEmpty()) {
        throw new \InvalidArgumentException('A countdown shows days, hours, minutes or seconds: '.$asked->implode(', '));
    }

    // Sorted back into place whatever order they were asked for — the units nest,
    // so the arithmetic below only works largest first.
    $visible = collect($order)->intersect($asked)->values();

    $labels = ['days' => 'Days', 'hours' => 'Hours', 'minutes' => 'Minutes', 'seconds' => 'Seconds'];
    $short = ['days' => 'd', 'hours' => 'h', 'minutes' => 'm', 'seconds' => 's'];
    $seconds = ['days' => 86400, 'hours' => 3600, 'minutes' => 60, 'seconds' => 1];

    $remaining = max(0, $target->getTimestamp() - now()->getTimestamp());
    $isExpired = $remaining <= 0;

    // The same split the browser runs, done once for the first paint, so the
    // markup arrives reading the right numbers instead of flashing zeros while
    // Alpine boots.
    $initial = [];

    foreach ($visible as $index => $unit) {
        $value = intdiv($remaining, $seconds[$unit]);
        $remaining -= $value * $seconds[$unit];
        $initial[$unit] = $index === 0 ? (string) $value : str_pad((string) $value, 2, '0', STR_PAD_LEFT);
    }

    $messageClasses = "{$sizes['message']} text-slate-500 dark:text-slate-400";
@endphp

@if ($isExpired)
    {{-- Already over before the page was drawn. No timer is booted, which is also
         what keeps expire="reload" from reloading into itself forever. --}}
    @if ($expire === 'message')
        <div {{ $attributes->class($messageClasses)->merge() }}>{!! $message !!}</div>
    @endif
@else
    <div
        x-data="countdownTimer({ target: {{ $target->getTimestampMs() }}, units: {!! \Illuminate\Support\Js::from($visible) !!}, expire: '{{ $expire }}' })"
        {{ $attributes->merge() }}
    >
        <div
            @class([
                'flex',
                $sizes['gap'],
                'items-baseline' => $variant === 'inline',
                'items-stretch' => $variant !== 'inline',
            ])
            @if ($expire !== 'reload') x-show="! expired" @endif
        >
            @foreach ($visible as $unit)
                @if ($variant === 'inline')
                    <span class="font-heading font-bold tabular-nums {{ $sizes['number'] }} {{ $toneText }}">
                        <span x-text="parts.{{ $unit }}">{{ $initial[$unit] }}</span>{{ $short[$unit] }}
                    </span>
                @else
                    <div @class([
                        'flex flex-col items-center justify-center text-center',
                        $sizes['tile'],
                        'rounded-xl '.$toneClasses => $variant === 'boxed',
                    ])>
                        <span
                            @class([
                                'font-heading font-bold leading-none tabular-nums',
                                $sizes['number'],
                                $toneText => $variant === 'plain',
                            ])
                            x-text="parts.{{ $unit }}"
                        >{{ $initial[$unit] }}</span>

                        <span @class([
                            'mt-1 font-medium uppercase tracking-wide',
                            $sizes['label'],
                            'opacity-70' => $variant === 'boxed',
                            'text-slate-500 dark:text-slate-400' => $variant === 'plain',
                        ])>{{ $labels[$unit] }}</span>
                    </div>
                @endif
            @endforeach
        </div>

        @if ($expire === 'message')
            <div x-show="expired" x-cloak class="{{ $messageClasses }}">{!! $message !!}</div>
        @endif
    </div>
@endif
