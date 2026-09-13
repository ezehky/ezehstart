<?php

use App\Traits\WithGateProps;
use App\Traits\WithSiteConfigProcessor;
use Livewire\Component;

/**
 * How the workspaces behave, as opposed to what they let somebody do.
 *
 * The home for switches that change the shape of a screen rather than the rules
 * behind it. Nothing here closes a route or guards anything — a preference that
 * did would belong on the security screen instead.
 */
new class extends Component
{
    use WithGateProps, WithSiteConfigProcessor;

    public function mount(): void
    {
        kSetSiteTitle('config', 'preferences');
        $this->setPageGate('config.preferences');
        $this->setConfigInitial();
    }

    protected function rules(): array
    {
        return [
            'config.user.mobile-floating-menu' => ['required', 'boolean'],

            // Telling a member when somebody else touched their files.
            'config.uploads.modification.email' => ['required', 'boolean'],
            'config.uploads.modification.in-app' => ['required', 'boolean'],
        ];
    }

    public function save(): bool
    {
        $this->checkGate();

        $this->saveConfig();

        $this->redirectRoute('admin.config.preferences', navigate: true);

        return $this->respondSuccess();
    }
};
?>

<form wire:submit="save" class="space-y-6">
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="space-y-6">
            <flux:card class="space-y-2">
                <div class="mb-4">
                    <flux:heading level="2" size="lg">Member workspace</flux:heading>
                    <flux:text class="mt-1">
                        How the member dashboard presents itself. Administrators are unaffected.
                    </flux:text>
                </div>

                <flux:switch
                    wire:model="config.user.mobile-floating-menu"
                    label="Floating menu on phones"
                    description="Show a bottom navigation bar on small screens, instead of the slide-out drawer."
                />
            </flux:card>

            <flux:card class="space-y-2">
                <div class="mb-4">
                    <flux:heading level="2" size="lg">Upload notices</flux:heading>
                    <flux:text class="mt-1">
                        A member's library is private to them, so a change they did not make is
                        worth hearing about rather than discovering. Nothing is sent when somebody
                        changes their own files.
                    </flux:text>
                </div>

                <flux:switch
                    wire:model="config.uploads.modification.email"
                    label="Email the member"
                    description="Send an email when an administrator edits, moves or deletes one of their uploads."
                />

                <flux:switch
                    wire:model="config.uploads.modification.in-app"
                    label="Notify in the dashboard"
                    description="Leave an entry in their bell menu for the same changes."
                />
            </flux:card>
        </div>
    </div>

    <div class="flex justify-end">
        <x-dashboard.gate.button
            :gate="$pageGate"
            :level="$gateModify"
            type="submit"
            variant="primary"
            icon="check"
        >
            Save preferences
        </x-dashboard.gate.button>
    </div>
</form>
