@props([
    'user',
    'size' => 'sm',
    'colored' => false,
    'ring' => false,
])

@php
$sizeClasses = match ($size) {
    'sm' => 'size-9',
    'md' => 'size-12',
    'lg' => 'size-16',
    default => throw new \InvalidArgumentException("Invalid size: {$size}"),
};
@endphp

<div
    {{ $attributes->class([
        'grid shrink-0 place-items-center overflow-hidden rounded-full text-sm font-semibold ',
        $sizeClasses,
        'bg-slate-900 text-white dark:bg-lime-400 dark:text-slate-950' => ! $colored,
        'bg-lime-400 text-slate-950' => $colored,
        'ring-2 ring-white/20' => $ring,
    ]) }}
>
    @if ($user->avatar)
        <img src="{{ $user->avatarUrl() }}" alt="{{ $user->name }} avatar" class="size-full object-cover" />
    @else
        <span aria-label="{{ $user->name }}">
            {{ $user->initials() }}
        </span>
    @endif
</div>
