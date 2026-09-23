<?php

use App\Enums\StatusUser;
use App\Models\User;
use App\Services\CaptchaService;
use App\Services\PasskeyService;
use App\Traits\WithAuthWorker;
use App\Traits\WithCaptcha;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::auth')] class extends Component
{
    use WithAuthWorker, WithCaptcha;

    public string $email;

    public string $password;

    public bool $remember = false;

    public function mount()
    {
        kSetSiteTitle('login');
    }

    /**
     * The captcha is not asked for on a first attempt. Somebody typing the right
     * password should not have to argue with a widget; somebody who has already
     * failed twice from this address should. captchaChallenged() counts by address
     * alone, so refreshing the page does not clear the challenge.
     */
    public function captchaRequired(): bool
    {
        return app(CaptchaService::class)->isAvailable() && $this->captchaChallenged();
    }

    protected function rules(): array
    {
        // Every rule this form has, in one method. Splitting them across property
        // attributes and a rules() method gives the screen two places to be edited
        // and one of them to be forgotten.
        return $this->captchaRules([
            'email' => ['required', 'email'],
            'password' => ['required'],
            'remember' => ['boolean'],
        ]);
    }

    public function login()
    {
        $this->validate();

        // Before the credential check, not after — the throttle only costs an
        // attacker anything if it runs on every attempt.
        $this->ensureIsNotRateLimited($this->email);

        // Attempt to authenticate the user with the provided credentials
        if (! Auth::attempt(['email' => $this->email, 'password' => $this->password], $this->remember)) {
            $this->recordFailedAttempt($this->email);

            // Counted separately from the throttle so the widget can be decided on
            // before an address has been typed. Hit before respondError() below,
            // which throws — a failure that never reached the counter would let an
            // attacker stay under the challenge for ever.
            $this->recordCaptchaFailure();

            $this->reset('password');

            // An address on the newsletter list has a users row but no account, so
            // no password will ever match it. Saying "wrong credentials" would send
            // somebody looking for a password they never set — the answer is to
            // register, which claims that row rather than colliding with it.
            $subscriberOnly = User::query()
                ->whereEmail($this->email)
                ->where('status', StatusUser::NEWSLETTER_SUBSCRIBER)
                ->exists();

            // Accounts created passwordlessly or through a social provider hold no
            // password at all, so point them at the flow that does work for them
            // rather than at a credential mismatch they cannot resolve.
            $hasNoPassword = ! $subscriberOnly
                && User::query()->whereEmail($this->email)->whereNull('password')->exists();

            $this->respondError(
                match (true) {
                    $subscriberOnly => __('That address is on our newsletter list but does not have an account yet. Please register to create one.'),
                    $hasNoPassword => __('This account has no password yet. Sign in with an email code, or reset your password to set one.'),
                    default => __('The provided credentials do not match our records.'),
                },
                true,
                field: 'email'
            );
        }

        // A correct password clears the count, so four mistypes followed by the
        // right one does not leave somebody throttled on their next sign-in.
        $this->clearRateLimit($this->email);
        $this->clearCaptchaFailures();

        // The password was right, but on an account with a second factor that is
        // only half the answer. This stands the session back down and sends them
        // to the code prompt; a null means there is no second factor to owe.
        if ($challenge = $this->twoFactorChallengeRedirect(auth()->user(), $this->remember)) {
            return $challenge;
        }

        // Log the user in and regenerate the session to prevent session fixation attacks
        $this->loginUser();

        // Redirect the user to their respective dashboard based on their role
        return $this->userDashboardRedirect();
    }

    // |||||||||||||||||||||||||||||||||||||||||||||||||||
    // Passkey

    /**
     * The challenge the browser asks the device to sign. 404 with the switch off,
     * so the button is not the only thing that goes away.
     */
    public function passkeyOptions(): string
    {
        abort_unless($this->passkeysEnabled, 404);

        return app(PasskeyService::class)->authenticationOptions();
    }

    /**
     * Sign in with the key that answered.
     *
     * No two-factor prompt afterwards. The challenge demands user verification,
     * so the device has already asked for a fingerprint, a face or a PIN — the
     * key is something the person has and the unlock is something they are or
     * know, which is the two factors the TOTP code would otherwise stand in for.
     */
    public function loginWithPasskey(string $credential)
    {
        abort_unless($this->passkeysEnabled, 404);

        // Keyed by address alone: there is no email on this path to key on, and a
        // browser replaying signed garbage should run out of tries like any other.
        $this->ensureIsNotRateLimited('passkey', field: 'passkey', prefix: 'passkey');

        $user = app(PasskeyService::class)->authenticate($credential);

        if (! $user) {
            $this->recordFailedAttempt('passkey', prefix: 'passkey');
        }

        $this->respondError(
            __('That passkey was not recognised. It may have been removed from your account.'),
            ! $user,
            field: 'passkey',
        );

        // The same line the social callback draws. An account inside its deletion
        // grace period may still come in — signing in is how it reaches the screen
        // that cancels the request.
        $this->respondError(
            __('Login Access Denied.').' '.$user->status->message(),
            $user->status->isSuspended() || $user->status->isDeleted(),
            field: 'passkey',
        );

        $this->clearRateLimit('passkey', prefix: 'passkey');

        Auth::login($user, $this->remember);

        $this->loginUser('Signed in with a passkey.');

        return $this->userDashboardRedirect();
    }
};
?>

<x-slot:tag>{{ __('Welcome back') }}</x-slot:tag>
<x-slot:title>{{ __('Sign in to your account') }}</x-slot:title>
<x-slot:description>{{ __('Pick up where you left off.') }}</x-slot:description>
<x-slot:extra>
    {{-- Only where the browser can make the request at all. The Alpine component
         reports support, so a browser without WebAuthn never sees a button that
         can only fail. --}}
    @if ($this->passkeysEnabled)
        <div x-data="passkey" x-show="supported" x-cloak class="my-3">
            <flux:button type="button" icon="finger-print" class="w-full" x-on:click="authenticate()" x-bind:disabled="busy">
                {{ __('Sign in with a passkey') }}
            </flux:button>
            <flux:error name="passkey" />
            <p x-show="error" x-text="error" class="mt-2 text-sm text-rose-600 dark:text-rose-400"></p>
        </div>
    @endif
    <x-auth.passwordless :enabled="$this->passwordlessEnabled" />
    <x-auth.social-providers :providers="$this->socialProviders" />
    <flux:text class="mt-8 text-center dark:text-slate-400">
        {{ __('New here?') }}
        <flux:link href="{{ route('register') }}" variant="ghost">
            {{ __('Create an account') }}
        </flux:link>
    </flux:text>
</x-slot:extra>

<form wire:submit.throttle.1000ms="login" class="mt-8 space-y-5">
    <flux:input
        :label="__('Email address')"
        wire:model="email"
        type="email"
        autofocus
        placeholder="you@example.com"
        icon="user"
    />

    <flux:field>
        <div class="flex items-center justify-between gap-4 mb-2">
            <flux:label>{{ __('Password') }}</flux:label>
            <flux:link href="{{ route('password.request') }}" variant="ghost" class="text-sm">
                {{ __('Forgot password?') }}
            </flux:link>
        </div>
        <x-form.password :label="null" wire:model="password" icon="lock-closed" />
        <flux:error name="password" />
    </flux:field>
    <flux:switch wire:model="remember" :label="__('Keep me signed in')" />

    {{-- Only after this address has been failing sign-ins. Hiding it is the
            courtesy; rules() is the boundary, and the two ask the same method. --}}
    @if ($this->captchaRequired())
        <x-form.captcha action="login" />
    @endif

    <flux:button type="submit" variant="primary" class="w-full">{{ __('Sign in') }}</flux:button>
</form>

