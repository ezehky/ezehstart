<?php

use App\Models\User;
use App\Rules\EmailRule;
use App\Services\PasskeyService;
use App\Traits\WithAuthWorker;
use App\Traits\WithCaptcha;
use App\Traits\WithPasswordTools;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::auth')] class extends Component
{
    use WithAuthWorker, WithCaptcha, WithPasswordTools;

    public string $name;

    public string $email;

    public string $password;

    public string $password_confirmation;

    public string $timezone = '';

    /**
     * Acceptance of the terms of service. Required — an account cannot be created
     * without it.
     */
    public bool $agreed_to_terms = false;

    public function mount(): void
    {
        kSetSiteTitle('Register');
    }

    protected function rules(): array
    {
        // Registration is asked for a captcha on every attempt rather than after a
        // failure. There is nothing to fail here — a script that posts this form
        // gets an account, and the first attempt is the one worth stopping.
        return $this->captchaRules([
            ...$this->detailsRules(),
            'password' => [
                'required',
                'string',
                'confirmed',
                $this->passwordStrengthRule(),
            ],
        ]);
    }

    /**
     * What every sign-up asks for, whichever way the account will be unlocked.
     */
    protected function detailsRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                new EmailRule,
                $this->emailAvailableRule(),
            ],
            'agreed_to_terms' => ['accepted'],
        ];
    }

    protected function messages(): array
    {
        return [
            'agreed_to_terms.accepted' => __('Please accept our policies to create an account.'),
        ];
    }

    public function register()
    {
        $this->validate();

        // Prepare the data for user creation
        $data = [
            'name' => $this->name,
            'email' => $this->email,
            'password' => $this->password,
            'timezone' => $this->timezone,
        ];

        $user = $this->createUser($data);

        // If user creation failed, respond with an error message
        $this->respondError(
            message: __('Failed to create user. Please try again.'),
            if: ! $user
        );

        return $this->afterRegistration();
    }

    // |||||||||||||||||||||||||||||||||||||||||||||||||||
    // Passkey

    /**
     * The challenge for a new key, once the details check out. The captcha is
     * spent here, on the step a script would have to pass first — the answer that
     * follows cannot carry a second token, and the challenge it must sign only
     * exists once this step has passed.
     *
     * 404 with passkeys off, so the button is not the only thing that goes away.
     */
    public function passkeyOptions(): string
    {
        abort_unless($this->passkeysEnabled, 404);

        $this->validate($this->captchaRules($this->detailsRules()));

        return app(PasskeyService::class)->signupOptions($this->name, $this->email);
    }

    /**
     * Open the account with the key the device just made, and no password.
     *
     * The account and its key are written together: a key that does not verify
     * rolls the account back, so a cancelled or forged answer never leaves an
     * account behind that nothing can open. The address is still unproven, so the
     * welcome code goes out exactly as it does for a password sign-up — and
     * "Forgot password" stays the way back in for somebody who loses the device.
     */
    public function registerWithPasskey(string $credential)
    {
        abort_unless($this->passkeysEnabled, 404);

        // Checked again rather than trusted from the first step: the address could
        // have been taken in between, and the form could have been edited since.
        $this->validate($this->detailsRules());

        $passkeys = app(PasskeyService::class);

        $this->respondError(
            __('The passkey prompt has expired. Please try again.'),
            ! $passkeys->hasPendingSignup($this->email),
            field: 'passkey',
        );

        $user = $this->createUser(
            ['name' => $this->name, 'email' => $this->email, 'timezone' => $this->timezone],
            within: fn (User $user) => $passkeys->completeSignup($user, $credential),
        );

        $this->respondError(
            __('Your device could not create the passkey. Please try again.'),
            ! $user,
            field: 'passkey',
        );

        return $this->afterRegistration();
    }

    /**
     * Where a new account goes next, however it was opened. createUser() has
     * signed it in by now, so the account is the signed-in one.
     */
    protected function afterRegistration()
    {
        // Redirect to email verification page if email verification is required and strict
        if (kSiteConfig('email-settings.verification-strict', default: false)) {
            return to_route('email.verification', ['user' => auth()->user()->email])
                ->with([
                    'message' => __('Please verify your email address to access this page.'),
                ]);
        }

        // Redirect the user to their respective dashboard based on their role
        return $this->userDashboardRedirect();
    }
};
?>

<x-slot:tag>{{ __('Get started') }}</x-slot:tag>
<x-slot:title>{{ __('Create your account') }}</x-slot:title>
<x-slot:description>{{ __('It takes less than a minute.') }}</x-slot:description>
<x-slot:extra>
    <x-auth.passwordless class="my-3" :enabled="$this->passwordlessEnabled" :label="__('Sign up without password.')" />
    <x-auth.social-providers :providers="$this->socialProviders" />
    <flux:text class="mt-8 text-center dark:text-slate-400">
        {{ __('Already have an account?') }}
        <flux:link href="{{ route('login') }}" variant="ghost">
            {{ __('Sign in') }}
        </flux:link>
    </flux:text>
</x-slot:extra>

{{-- withPasskey swaps the password for a key made on this device. The rest of
     the form is shared: a passkey account still has a name, an address and the
     same consent, captcha and welcome code as any other. --}}
<form wire:submit.throttle.500ms="register" x-data="{ withPasskey: false }" class="mt-8 space-y-5">
    <div>
        <flux:input wire:model="name" autocomplete="name" autofocus :placeholder="__('Enter your full name')" />
        <flux:error name="name" />
    </div>
    <div>
        <flux:input
            wire:model="email"
            type="email"
            autocomplete="email"
            placeholder="example@mail.com"
        />
        <flux:error name="email" />
    </div>

    <div x-show="! withPasskey">
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-2">
            <flux:field>
                <flux:label>{{ __('Password') }}</flux:label>
                <x-form.password wire:model="password" :label="null" :note="$passwordNote" />
            </flux:field>
            <x-form.password :label="__('Confirm password')" wire:model="password_confirmation" />
        </div>
        <flux:error name="password" />
    </div>

    <x-form.consent-field />

    @if ($this->captchaRequired())
        <x-form.captcha action="register" />
    @endif

    {{-- Inside the component root, not the layout's extra slot: the key ceremony
         calls back through $wire, and the slot is printed outside the component. --}}
    <div x-data="passkey" class="space-y-3">
        {{-- Disabled rather than only hidden while a passkey is chosen. A hidden
             submit button is still the form's default, and Enter would post the
             password form with no password in it. --}}
        <flux:button type="submit" variant="primary" class="w-full" x-show="! withPasskey" x-bind:disabled="withPasskey">
            {{ __('Create account') }}
        </flux:button>

        @if ($this->passkeysEnabled)
            <flux:button
                type="button"
                variant="primary"
                icon="finger-print"
                class="w-full"
                x-show="withPasskey"
                x-cloak
                x-on:click="register('passkeyOptions', 'registerWithPasskey')"
                x-bind:disabled="busy"
            >
                {{ __('Create account with a passkey') }}
            </flux:button>
            <flux:error name="passkey" />
            <p x-show="error" x-text="error" x-cloak class="text-sm text-rose-600 dark:text-rose-400"></p>

            {{-- Only where the browser can make a key at all. --}}
            <div x-show="supported" x-cloak class="text-center">
                <button
                    type="button"
                    x-on:click="withPasskey = ! withPasskey; error = null"
                    class="text-sm font-medium text-lime-700 hover:text-lime-800 dark:text-lime-400"
                    x-text="withPasskey ? @js(__('Use a password instead')) : @js(__('Sign up with a passkey instead'))"
                ></button>
            </div>
        @endif
    </div>

    @script
        <script>
            // The browser is the only thing that knows the visitor's zone. Report it
            // once so timestamps render locally before the account has a saved one.
            const timezone = new Intl.DateTimeFormat().resolvedOptions().timeZone;
            $wire.timezone = timezone;

            fetch('/set-timezone', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                },
                body: JSON.stringify({ timezone })
            });
        </script>
    @endscript
</form>
