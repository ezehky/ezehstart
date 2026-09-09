@props(['user' => null, 'matrix' => [], 'pending' => null])

<flux:modal name="userRolesModal" class="md:w-135">
    <div class="space-y-6">
        <div>
            <flux:heading size="lg">Manage roles</flux:heading>
            <flux:text class="mt-1">
                @if ($user)
                    Grant or remove workspace roles for <span class="font-medium">{{ $user->name }}</span>.
                @else
                    Grant or remove workspace roles.
                @endif
            </flux:text>
        </div>

        <div class="divide-y divide-slate-100 dark:divide-slate-800">
            @foreach ($matrix as $entry)
                <div wire:key="role-entry-{{ $entry['role'] }}" class="flex items-start justify-between gap-4 py-4 first:pt-0 last:pb-0">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <span class="font-semibold text-slate-900 dark:text-white">{{ $entry['label'] }}</span>
                            @if ($entry['has'])
                                <flux:badge size="sm" color="lime">Assigned</flux:badge>
                            @endif
                        </div>
                        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ $entry['description'] }}</p>
                        @if ($entry['blocked'])
                            <p class="mt-2 text-xs font-medium text-amber-600 dark:text-amber-400">
                                {{ $entry['blocked'] }}
                            </p>
                        @endif
                    </div>

                    @if ($entry['action'] === 'revoke')
                        <flux:button
                            variant="danger"
                            size="sm"
                            icon="minus-circle"
                            class="shrink-0"
                            :disabled="(bool) $entry['blocked']"
                            wire:click="confirmRoleAction('{{ $entry['role'] }}')"
                        >
                            Remove
                        </flux:button>
                    @elseif ($entry['action'] === 'switch')
                        <flux:button
                            variant="primary"
                            size="sm"
                            icon="arrows-right-left"
                            class="shrink-0"
                            :disabled="(bool) $entry['blocked']"
                            wire:click="confirmRoleAction('{{ $entry['role'] }}')"
                        >
                            Switch to {{ $entry['label'] }}
                        </flux:button>
                    @else
                        <flux:button
                            variant="primary"
                            size="sm"
                            icon="plus-circle"
                            class="shrink-0"
                            :disabled="(bool) $entry['blocked']"
                            wire:click="grantRole('{{ $entry['role'] }}')"
                        >
                            Grant
                        </flux:button>
                    @endif
                </div>
            @endforeach
        </div>

        <div class="flex justify-end">
            <flux:modal.close>
                <flux:button variant="ghost">Done</flux:button>
            </flux:modal.close>
        </div>
    </div>
</flux:modal>

{{-- Sits beside the roles modal rather than inside it, so the confirmation stacks
     over a dialog that is still open and the matrix is back the moment it closes. --}}
@if ($pending)
    <x-dashboard.confirm-modal
        name="roleActionModal"
        :title="$pending['action'] === 'switch'
            ? 'Switch this account to '.$pending['label'].'?'
            : 'Remove the '.$pending['label'].' role?'"
        :icon="$pending['action'] === 'switch' ? 'arrows-right-left' : 'minus-circle'"
        :variant="$pending['action'] === 'switch' ? 'primary' : 'danger'"
        :tone="$pending['action'] === 'switch' ? 'sky' : 'rose'"
        :confirm="$pending['action'] === 'switch' ? 'Switch role' : 'Remove role'"
        cancel="Leave it as it is"
        wire:click="applyRoleAction"
    >
        @if ($pending['action'] === 'switch')
            The role this account holds now is given up in exchange. It keeps its data, but the
            workspace it signs into changes.
        @else
            {{ $user?->name ?? 'This account' }} loses access to everything the
            {{ $pending['label'] }} role opens. It can be granted again later.
        @endif
    </x-dashboard.confirm-modal>
@endif
