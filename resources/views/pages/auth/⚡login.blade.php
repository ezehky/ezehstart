<?php

use App\Enums\SocialProviderEnum;
use App\Models\User;
use App\Services\SocialAccountService;
use App\Traits\WithAuthWorker;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

new #[Layout('layouts::auth')] class extends Component
{
    use WithAuthWorker;

    #[Validate(['required', 'email'])]
    public string $email;

    #[Validate(['required'])]
    public string $password;

    #[Validate(['boolean'])]
    public bool $remember = false;

    public function mount()
    {
        kSetSiteTitle('login');
    }

    /**
     * The providers this install can actually sign somebody in with. Empty
     * whenever the feature is off or nothing has credentials.
     *
     * @return Collection<int, SocialProviderEnum>
     */
    #[Computed]
    public function socialProviders(): Collection
    {
        $service = app(SocialAccountService::class);

        return $service->isAvailable() ? $service->enabledProviders() : collect();
    }

    #[Computed]
    public function passwordlessEnabled(): bool
    {
        return (bool) kSiteFlag('security', 'passwordless-login', true);
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
    <flux:text class="mt-8 text-center dark:text-slate-400">
        New here?
        <flux:link href="{{ route('register') }}" variant="ghost">
            Create an account
        </flux:link>
    </flux:text>
</x-slot:extra>

<div>
    @session('status')
        <flux:callout color="lime" class="mt-6 text-sm">{!! session('status') !!}</flux:callout>
    @endsession

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

        <flux:button type="submit" variant="primary" class="w-full">Sign in</flux:button>

        @if ($this->passwordlessEnabled)
            <flux:button href="{{ route('passwordless') }}" icon="envelope" class="w-full">
                Email me a sign-in code
            </flux:button>
        @endif
    </form>

    {{-- Social sign-in. Only rendered when the site switch is on and at least one
         provider actually has credentials — a button that lands on a provider
         error page is worse than no button. --}}
    @if ($this->socialProviders->isNotEmpty())
        <div class="mt-6 flex items-center gap-3">
            <flux:separator class="grow" />
            <flux:text size="sm" class="shrink-0">or continue with</flux:text>
            <flux:separator class="grow" />
        </div>

        <div class="mt-6 space-y-3">
            @foreach ($this->socialProviders as $provider)
                <flux:button
                    wire:key="social-{{ $provider->value }}"
                    href="{{ route('social.redirect', $provider->value) }}"
                    class="w-full"
                >
                    {{ $provider->label() }}
                </flux:button>
            @endforeach
        </div>
    @endif
</div>

