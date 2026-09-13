<?php

use App\Models\User;
use App\Services\CaptchaService;
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

            // Accounts created passwordlessly or through a social provider hold no
            // password at all, so point them at the flow that does work for them
            // rather than at a credential mismatch they cannot resolve.
            $hasNoPassword = User::query()->whereEmail($this->email)->whereNull('password')->exists();

            $this->respondError(
                $hasNoPassword
                    ? 'This account has no password yet. Sign in with an email code, or reset your password to set one.'
                    : 'The provided credentials do not match our records.',
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
};
?>

<x-slot:tag>Welcome back</x-slot:tag>
<x-slot:title>Sign in to your account</x-slot:title>
<x-slot:description>Pick up where you left off.</x-slot:description>
<x-slot:extra>
    <x-auth.passwordless :enabled="$this->passwordlessEnabled" />
    <x-auth.social-providers :providers="$this->socialProviders" />
    <flux:text class="mt-8 text-center dark:text-slate-400">
        New here?
        <flux:link href="{{ route('register') }}" variant="ghost">
            Create an account
        </flux:link>
    </flux:text>
</x-slot:extra>

<form wire:submit.throttle.1000ms="login" class="mt-8 space-y-5">
    <flux:input
        label="Email address"
        wire:model="email"
        type="email"
        autofocus
        placeholder="you@example.com"
        icon="user"
    />

    <flux:field>
        <div class="flex items-center justify-between gap-4 mb-2">
            <flux:label>Password</flux:label>
            <flux:link href="{{ route('password.request') }}" variant="ghost" class="text-sm">
                Forgot password?
            </flux:link>
        </div>
        <x-form.password :label="null" wire:model="password" icon="lock-closed" />
        <flux:error name="password" />
    </flux:field>
    <flux:switch wire:model="remember" label="Keep me signed in" />

    {{-- Only after this address has been failing sign-ins. Hiding it is the
            courtesy; rules() is the boundary, and the two ask the same method. --}}
    @if ($this->captchaRequired())
        <x-form.captcha action="login" />
    @endif

    <flux:button type="submit" variant="primary" class="w-full">Sign in</flux:button>
</form>

