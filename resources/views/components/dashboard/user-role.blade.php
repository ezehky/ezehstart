@props(['user'])

{{-- The role an account carries, for a listing cell.

     Members are shown as a dash rather than as "no role": having none is what a
     member *is*, and an empty-state warning against every member row would train
     people to ignore the one that matters. An admin without a live role is the row
     that matters, so that one is called out. --}}

<div {{ $attributes->class(['flex flex-wrap items-center gap-1']) }}>
    @if (! $user->type->carriesRole())
        <span class="text-sm text-slate-400">—</span>
    @elseif (! $user->role)
        <flux:badge size="sm" color="amber">No role</flux:badge>
    @elseif (! $user->role->grantsAccess())
        <flux:badge size="sm" color="amber">{{ $user->role->name }} (off)</flux:badge>
    @else
        <flux:badge size="sm" :color="$user->role->is_protected ? 'purple' : 'blue'">
            {{ $user->role->name }}
        </flux:badge>
    @endif
</div>
