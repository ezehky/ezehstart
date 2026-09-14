<?php

use App\Enums\SocialProviderEnum;
use App\Services\CaptchaService;
use App\Traits\WithGateProps;
use App\Traits\WithSiteConfigProcessor;
use Livewire\Component;

new class extends Component
{
    use WithGateProps, WithSiteConfigProcessor;

    protected function configSubject(): string
    {
        return 'security';
    }

    public function mount(): void
    {
        kSetSiteTitle('config', 'security');
        $this->setPageGate('config.security');
    }

    /**
     * Whether the Turnstile keys are in the environment. The switch is still
     * saveable without them — an administrator may be setting the site up before
     * the keys arrive — but the screen says so, because a switch that is on and
     * does nothing is worse than one that is off.
     */
    public function captchaConfigured(): bool
    {
        return app(CaptchaService::class)->isConfigured();
    }

    /**
     * The providers that have credentials, for the per-provider switches. A
     * provider with nothing in .env has nothing to switch.
     *
     * @return array<int, SocialProviderEnum>
     */
    public function configuredProviders(): array
    {
        return array_filter(
            SocialProviderEnum::cases(),
            fn (SocialProviderEnum $provider) => $provider->isConfigured(),
        );
    }

    /**
     * A switch that is off takes its dependent fields off the screen with it, so
     * those fields are only validated while they are actually being shown.
     *
     * Leaving them in the list unconditionally would fail the save on a field the
     * administrator cannot see — and there is nothing to enforce anyway, because
     * nothing reads a dependent value while its feature is off.
     */
    protected function rules(): array
    {
        $rules = [
            'config.email-settings.verification' => ['required', 'boolean'],

            // User
            'config.user.account-deletion' => ['required', 'boolean'],
            'config.user.allow-data-download' => ['required', 'boolean'],

            // Security. Every one of these closes a route as well as hiding a
            // button, so turning one off is a real change rather than cosmetic.
            'config.security.strong-password' => ['required', 'boolean'],
            'config.security.password-min-length' => ['required', 'integer', 'min:5', 'max:16'],

            'config.security.password-history' => ['required', 'boolean'],
            'config.security.two-factor' => ['required', 'boolean'],
            'config.security.socialite' => ['required', 'boolean'],
            'config.security.passwordless-login' => ['required', 'boolean'],
            'config.security.captcha' => ['required', 'boolean'],
            // Bounded here as well as in the service: the service clamps whatever
            // it reads, and this stops a silly number being saved in the first place.
            'config.security.login-max-attempts' => ['required', 'integer', 'min:3', 'max:20'],
            'config.security.login-decay-minutes' => ['required', 'integer', 'min:1', 'max:60'],

            // Zero is "keep forever". Anything above it is floored at 30 days by the
            // service as well — a one-day audit trail is not a setting, it is a mistake.
            'config.security.activity-log-retention-days' => ['required', 'integer', 'min:0', 'max:3650'],

            // Uploads
            'config.uploads.user-image-limit' => ['required', 'integer', 'min:0', 'max:10000'],
            'config.uploads.max-image-size' => ['required', 'integer', 'min:64', 'max:20480'],
            'config.uploads.optimize-images' => ['required', 'boolean'],
            'config.uploads.user-video-limit' => ['required', 'integer', 'min:0', 'max:10000'],
        ];

        // Strict verification is a rule about how verification behaves. With
        // verification off there is nothing for it to be strict about.
        if ((bool) data_get($this->config, 'email-settings.verification')) {
            $rules['config.email-settings.verification-strict'] = ['required', 'boolean'];
        }

        // The grace period before an account is really gone, which only exists if
        // users may delete their account in the first place.
        if ((bool) data_get($this->config, 'user.account-deletion')) {
            $rules['config.user.account-deletion-days'] = ['required', 'integer', 'min:1', 'max:365'];

            // What happens when the grace period runs out. Only meaningful while
            // members can ask to be deleted in the first place.
            $rules['config.user.anonymous-after-deletion'] = ['required', 'boolean'];
        }

        // How many previous hashes to keep. Meaningless — and a liability — when the
        // history check itself is off.
        if ((bool) data_get($this->config, 'security.password-history')) {
            $rules['config.security.password-history-depth'] = ['required', 'integer', 'min:1', 'max:24'];
        }

        // One switch per provider, and only the providers that actually have
        // credentials — the rest are not on the screen to be validated.
        if ((bool) data_get($this->config, 'security.socialite')) {
            foreach ($this->configuredProviders() as $provider) {
                $rules["config.security.social-providers.{$provider->value}"] = ['required', 'boolean'];
            }
        }

        return $rules;
    }

    public function save(): bool
    {
        $this->checkGate();

        $this->saveConfig();

        $this->redirectRoute('admin.config.security', navigate: true);

        return $this->respondSuccess();
    }
};
?>

<form wire:submit="save" class="space-y-6">
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <flux:card class="space-y-2">
            <flux:heading level="2" size="lg" class="mb-4">Email Verification</flux:heading>
            <flux:switch
                wire:model.live="config.email-settings.verification"
                label="Enabled"
                description="Turn on email verification for new users."
            />

            @if (data_get($config, 'email-settings.verification'))
                <flux:switch
                    wire:model="config.email-settings.verification-strict"
                    label="Strict email verification"
                    description="Users cannot reach their workspace until the address is verified."
                />
            @endif
        </flux:card>
        <flux:card class="space-y-2">
            <flux:heading level="2" size="lg" class="mb-4">Account deletion</flux:heading>
            <flux:switch
                wire:model.live="config.user.account-deletion"
                label="Enabled"
                description="Users may delete their own account."
            />
            @if (data_get($config, 'user.account-deletion'))
                <x-form.number-field
                    wire:model="config.user.account-deletion-days"
                    label="Account deletion days"
                    placeholder="e.g. 30"
                    min="1"
                    max="365"
                />

                <flux:switch
                    wire:model="config.user.anonymous-after-deletion"
                    label="Anonymize instead of deleting"
                    description="Keep the account row with its personal details stripped, so history that points at it stays readable. Off removes the account and everything it owns, including its library files."
                />
            @endif
        </flux:card>
        <flux:card class="space-y-2">
            <flux:heading level="2" size="lg" class="mb-4">Account data</flux:heading>
            <flux:switch
                wire:model="config.user.allow-data-download"
                label="Allow data download"
                description="Users may download a copy of what is held about them — profile, consents, transactions and library index, as one JSON file. Security material is never included. Turning this off closes the route as well as hiding the tab."
            />
        </flux:card>
    </div>
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 space-y-6">
            <flux:card class="space-y-3">
                <div class="mb-4">
                    <flux:heading level="2" size="lg">Image library</flux:heading>
                    <flux:text class="mt-1">
                        Limits applied to users. Administrators upload against the filesystem,
                        not against a quota.
                    </flux:text>
                </div>

                <div class="grid gap-2 md:grid-cols-2">
                    <x-form.number-field
                        wire:model="config.uploads.user-image-limit"
                        label="Images per member"
                        placeholder="e.g. 50"
                        min="0"
                        max="10000"
                        description:trailing="0 means no limit."
                    />

                    <x-form.number-field
                        wire:model="config.uploads.max-image-size"
                        label="Maximum file size (KB)"
                        placeholder="e.g. 2048"
                        min="64"
                        max="20480"
                        description:trailing="min 64KB, max 20MB."
                    />
                </div>

                <flux:switch
                    wire:model="config.uploads.optimize-images"
                    label="Optimise uploads"
                    description="Needs jpegoptim, optipng and pngquant on the server. Where they are missing this does nothing rather than failing."
                />

                {{-- A video costs no disk, so this is not a storage limit. It is
                    there because a library nobody can find anything in is not a
                    library. --}}
                <x-form.number-field
                    wire:model="config.uploads.user-video-limit"
                    label="Videos per member"
                    placeholder="e.g. 25"
                    min="0"
                    max="10000"
                />
            </flux:card>
        </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <div class="space-y-6">
            <flux:card class="space-y-3">
                <flux:heading level="2" size="lg" class="mb-4">Passwords</flux:heading>

                <flux:switch
                    wire:model="config.security.strong-password"
                    label="Require strong passwords"
                    description="A symbol, a number and mixed case."
                />
                <x-form.number-field
                    wire:model="config.security.password-min-length"
                    label="Minimum password length"
                    placeholder="e.g. 5"
                    min="5"
                    max="16"
                />
                <flux:separator variant="subtle" class="my-5" />
                <flux:switch
                    wire:model.live="config.security.password-history"
                    label="Refuse reused passwords"
                    description="Checks a new password against the ones this account has already had."
                />
                {{-- Only asked for while the history check is on. Holding old hashes
                    the app never compares is a liability with no matching benefit,
                    so the depth is not a setting when the feature is off. --}}
                @if (data_get($config, 'security.password-history'))
                    <x-form.number-field
                        wire:model="config.security.password-history-depth"
                        label="Passwords remembered"
                        placeholder="e.g. 5"
                        min="1"
                        max="24"
                    />
                @endif
            </flux:card>
            <flux:card class="space-y-4">
                <flux:heading level="2" size="lg" class="mb-4">Socialite Sign-in</flux:heading>
                <flux:switch
                    wire:model.live="config.security.socialite"
                    label="Offer social sign-in"
                    description="Only providers with credentials in the environment are shown."
                />

                {{-- Credentials and permission are two different questions. A
                     provider can be taken off the sign-in page for a while without
                     anybody emptying .env, and the redirect route reads the same
                     switch, so turning one off closes it rather than hiding it. --}}
                @if (data_get($config, 'security.socialite'))
                    @forelse ($this->configuredProviders() as $provider)
                        <flux:switch
                            wire:key="provider-{{ $provider->value }}"
                            wire:model="config.security.social-providers.{{ $provider->value }}"
                            label="{{ $provider->label() }}"
                            class="ms-4"
                        />
                    @empty
                        <flux:callout color="amber" class="text-sm">
                            No provider has credentials in the environment yet, so nothing is offered.
                        </flux:callout>
                    @endforelse
                @endif
            </flux:card>
        </div>
        <div class="space-y-6">
            <flux:card class="space-y-4">
                <flux:heading level="2" size="lg" class="mb-4">Sign-in Configuration</flux:heading>

                <flux:switch
                    wire:model="config.security.two-factor"
                    label="Offer two-factor authentication"
                    description="Requires a TOTP authenticator app on the member's device."
                />

                <flux:separator variant="subtle" />
                <flux:switch
                    wire:model="config.security.passwordless-login"
                    label="Offer passwordless sign-in"
                    description="Signing in with a six-digit code sent by email."
                />

                <flux:separator variant="subtle" />
                <flux:switch
                    wire:model="config.security.captcha"
                    label="Require a captcha on the guest forms"
                    description="Cloudflare Turnstile on registration, passwordless sign-in and password reset. The sign-in form only asks for it once an address has failed twice."
                />

                @unless ($this->captchaConfigured())
                    <flux:callout color="amber" class="text-sm">
                        TURNSTILE_SITE_KEY and TURNSTILE_SECRET_KEY are not set, so this
                        stays off however it is saved here.
                    </flux:callout>
                @endunless

                <flux:separator variant="subtle" />
                <flux:heading level="3" size="sm">Sign-in throttle</flux:heading>
                <flux:text class="mt-1">
                    Failed attempts are counted per email and address together, so nobody can
                    lock somebody else out by failing against their address on purpose.
                </flux:text>
                <div class="grid gap-3 md:grid-cols-2">
                    <x-form.number-field
                        wire:model="config.security.login-max-attempts"
                        label="Attempts allowed"
                        placeholder="e.g. 5"
                        min="3"
                        max="20"
                    />
                    <x-form.number-field
                        wire:model="config.security.login-decay-minutes"
                        label="Lockout minutes"
                        placeholder="e.g. 1"
                        min="1"
                        max="60"
                    />
                </div>

                <flux:separator variant="subtle" />
                <flux:heading level="3" size="sm">Audit trail retention</flux:heading>
                <flux:text class="mt-1">
                    How many days of activity log to keep. Leave it at 0 to keep everything —
                    the nightly sweep then does nothing. Anything above 0 is held to a floor of
                    30 days, and entries older than the window are deleted for good.
                </flux:text>
                <div class="grid gap-3 md:grid-cols-2">
                    <x-form.number-field
                        wire:model="config.security.activity-log-retention-days"
                        label="Days to keep"
                        placeholder="0 keeps everything"
                        min="0"
                        max="3650"
                    />
                </div>
            </flux:card>
        </div>
    </div>

    <x-util.floating-actions position="end">
        <x-dashboard.gate.button :gate="$pageGate" :level="$gateModify" icon="check">
            Save changes
        </x-dashboard.gate.button>
    </x-util.floating-actions>
</form>
