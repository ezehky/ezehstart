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
