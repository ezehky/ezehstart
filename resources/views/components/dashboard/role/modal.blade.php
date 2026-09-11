@props(['user', 'roles', 'type', 'blocked' => null])

{{-- Type and roles are one form because they are one decision: a role only exists
     inside the admin workspace, so choosing roles for an account that is about to
     stop being an admin is not a thing the screen should let anybody express.

     The roles box is a set of checkboxes rather than a select, because an admin holds
     any number and their access is the sum. What is ticked is what the account ends up
     holding — unticking a role takes it away. --}}

<flux:modal name="userRolesModal" class="md:w-135">
    <form wire:submit="saveRoleAccess" class="space-y-6">
        <div>
            <flux:heading size="lg">Account access</flux:heading>
            <flux:text class="mt-1">
                @if ($user)
                    Which workspace <span class="font-medium">{{ $user->name }}</span> signs in to, and what they reach once inside.
                @else
                    Which workspace this account signs in to, and what they reach once inside.
                @endif
            </flux:text>
        </div>

        <flux:select wire:model.live="accountType" label="Account type" badge="required">
            @foreach (App\Enums\UserTypeEnum::forSelect() as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </flux:select>

        @if ($type?->carriesRole())
            <flux:checkbox.group
                wire:model.live="accountRoles"
                label="Roles"
                description="What this administrator reaches. Holding more than one adds the access up; tick none to let them sign in while somebody decides."
            >
                @forelse ($roles as $role)
                    <flux:checkbox
                        :value="(string) $role->id"
                        :label="$role->name"
                        :description="$role->description"
                    />
                @empty
                    <flux:text class="text-sm">
                        No live roles exist yet. Create one on the Roles screen first.
                    </flux:text>
                @endforelse
            </flux:checkbox.group>
        @else
            <flux:callout icon="information-circle" color="zinc">
                <flux:callout.text>
                    users carry no roles. The member workspace is not gated — an account reaches
                    its own records and nothing else.
                </flux:callout.text>
            </flux:callout>
        @endif

        @if ($blocked)
            <flux:callout icon="exclamation-triangle" color="amber">
                <flux:callout.text>{{ $blocked }}</flux:callout.text>
            </flux:callout>
        @endif

        <div class="flex justify-end gap-3">
            <flux:modal.close>
                <flux:button variant="ghost">Cancel</flux:button>
            </flux:modal.close>

            <flux:button type="submit" variant="primary" :disabled="(bool) $blocked">
                Save access
            </flux:button>
        </div>
    </form>
</flux:modal>
