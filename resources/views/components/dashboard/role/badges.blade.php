@props(['user'])

{{-- The roles an account carries, for a listing cell.

     An admin holds any number of them and their access is the sum, so all of them are
     shown rather than a first-one-wins summary: "Media" beside "Support" is the whole
     answer to what the account reaches, and either one alone would be a lie.

     Members are shown as a dash rather than as "no role": having none is what a member
     *is*, and an empty-state warning against every member row would train people to
     ignore the one that matters. An admin with nothing live is the row that matters,
     so that one is called out. --}}

<div {{ $attributes->class(['flex flex-wrap items-center gap-1']) }}>
    @if (! $user->user_type->carriesRole())
        <span class="text-sm text-slate-400">—</span>
    @elseif ($user->roles->isEmpty())
        <flux:badge size="sm" color="amber">No role</flux:badge>
    @else
        @foreach ($user->roles as $role)
            @if (! $role->grantsAccess())
                <flux:badge size="sm" color="amber">{{ $role->name }} (off)</flux:badge>
            @else
                <flux:badge size="sm" :color="$role->is_protected ? 'purple' : 'blue'">
                    {{ $role->name }}
                </flux:badge>
            @endif
        @endforeach
    @endif
</div>
