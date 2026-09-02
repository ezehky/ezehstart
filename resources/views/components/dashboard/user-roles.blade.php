@props(['roles'])

<div {{ $attributes->class(['flex flex-wrap items-center gap-1']) }}>
    @forelse ($roles as $role)
        <flux:badge
            size="sm"
            :color="match ($role->value) {
                'admin' => 'purple',
                'trainer' => 'sky',
                'student' => 'lime',
                default => 'zinc',
            }"
        >
            {{ $role->label() }}
        </flux:badge>
    @empty
        <span class="text-sm text-slate-400">No role</span>
    @endforelse
</div>
