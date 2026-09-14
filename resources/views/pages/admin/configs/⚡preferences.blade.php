<?php

use App\Services\NewsletterService;
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

    protected function configSubject(): string
    {
        return 'preferences';
    }

    public function mount(): void
    {
        kSetSiteTitle('config', 'preferences');
        $this->setPageGate('config.preferences');
        $this->setConfigInitial();
    }

    /**
     * A switch that is off takes its dependent fields off the screen with it, so
     * those fields are only validated while they are actually being shown —
     * otherwise the save fails on a field the administrator cannot see.
     */
    protected function rules(): array
    {
        $rules = [
            'config.preferences.mobile-floating-menu' => ['required', 'boolean'],
            'config.preferences.accept-cookies' => ['required', 'boolean'],
            'config.preferences.newsletter.status' => ['required', 'boolean'],

            // Telling a member when somebody else touched their files.
            'config.uploads.modification.email' => ['required', 'boolean'],
            'config.uploads.modification.in-app' => ['required', 'boolean'],
        ];

        // Where the sign-up appears. Neither placement means anything while the
        // newsletter itself is off — nothing renders and the form refuses to write.
        if ((bool) data_get($this->config, 'preferences.newsletter.status')) {
            $rules['config.preferences.newsletter.footer'] = ['required', 'boolean'];
            $rules['config.preferences.newsletter.popup'] = ['required', 'boolean'];

            // How long the reader gets first, asked for only while there is a popup
            // to delay. Bounded here as well as in the service: the service clamps
            // whatever it reads, and this stops a silly number being saved at all.
            if ((bool) data_get($this->config, 'preferences.newsletter.popup')) {
                $rules['config.preferences.newsletter.popup-delay'] = [
                    'required', 'integer', 'min:1', 'max:'.NewsletterService::MAX_POPUP_DELAY,
                ];
            }
        }

        return $rules;
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
                    wire:model="config.preferences.mobile-floating-menu"
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

        <div class="space-y-6">
            <flux:card class="space-y-2">
                <div class="mb-4">
                    <flux:heading level="2" size="lg">Cookie notice</flux:heading>
                    <flux:text class="mt-1">
                        The bar along the bottom of every page until a visitor acknowledges it.
                        It describes the sign-in cookies this site sets; an install that adds
                        analytics needs a real choice rather than a notice, and should turn this
                        off and put that in its place.
                    </flux:text>
                </div>

                <flux:switch
                    wire:model="config.preferences.accept-cookies"
                    label="Show the cookie notice"
                    description="Links to the published Cookie Policy. Dismissing it is remembered by the visitor's browser."
                />
            </flux:card>

            <flux:card class="space-y-2">
                <div class="mb-4">
                    <flux:heading level="2" size="lg">Newsletter</flux:heading>
                    <flux:text class="mt-1">
                        The sign-up form on the public pages. Addresses are stored; sending is
                        not part of the kit.
                    </flux:text>
                </div>

                <flux:switch
                    wire:model.live="config.preferences.newsletter.status"
                    label="Enabled"
                    description="Off takes both placements off the site, and the form refuses an address even if a page is still open on somebody's screen."
                />

                @if (data_get($config, 'preferences.newsletter.status'))
                    <flux:separator variant="subtle" class="my-5" />

                    <flux:switch
                        wire:model="config.preferences.newsletter.footer"
                        label="In the footer"
                        description="A block above the legal links, on every public page."
                    />

                    <flux:switch
                        wire:model.live="config.preferences.newsletter.popup"
                        label="As a popup"
                        description="A card in the corner, after the delay below. Dismissing it is remembered by the visitor's browser."
                    />

                    {{-- Only asked for while there is a popup to delay. --}}
                    @if (data_get($config, 'preferences.newsletter.popup'))
                        <x-form.number-field
                            wire:model="config.preferences.newsletter.popup-delay"
                            label="Popup delay (seconds)"
                            placeholder="e.g. 5"
                            min="1"
                            max="{{ App\Services\NewsletterService::MAX_POPUP_DELAY }}"
                        />
                    @endif
                @endif
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
