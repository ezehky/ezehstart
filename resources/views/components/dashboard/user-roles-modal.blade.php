@props(['user', 'roles', 'type', 'blocked' => null])

{{-- Type and role are one form because they are one decision: a role only exists
     inside the admin workspace, so choosing a role for an account that is about to
     stop being an admin is not a thing the screen should let anybody express. --}}

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
            <flux:select
                wire:model.live="accountRole"
                label="Role"
                description="What this administrator reaches. Leave it empty to let them sign in while somebody decides."
            >
                <option value="">No role yet</option>
                @foreach ($roles as $role)
                    <option value="{{ $role->id }}">{{ $role->name }}</option>
                @endforeach
            </flux:select>
        @else
            <flux:callout icon="information-circle" color="zinc">
                <flux:callout.text>
                    Members carry no role. The member workspace is not gated — an account reaches
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
