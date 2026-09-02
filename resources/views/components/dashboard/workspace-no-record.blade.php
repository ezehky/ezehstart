@props([
    'label' => null,
    'icon' => 'folder-open',
    'text' => 'This area is ready for the related cohort records. Add the corresponding data model to activate its management tools.',
    'button' => null,
    'border' => false,
])
<div {{ $attributes->class([
        'py-12 text-center text-sm text-slate-500',
        'rounded-lg border border-zinc-300 dark:border-zinc-700' => $border,
    ])->merge() }}>
    @if ($icon)
        <flux:icon name="{{ $icon }}" class="mx-auto size-8 text-slate-400" />
    @endif
    @if ($label)
        <flux:heading level="2" size="lg" class="mt-4">
            {!! kBreakText($label) !!}
        </flux:heading>
    @endif
    <flux:text class="mx-auto mt-2 max-w-md">
        {!! $text !!}
    </flux:text>

    @if ($button)
        <div class="mt-6">
            {!! $button !!}
        </div>
    @endif
</div>
