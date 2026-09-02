<?php

use App\Models\User;
use App\Traits\WithAuthWorker;
use Illuminate\Support\Facades\Auth;
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

    public function login()
    {
        $this->validate();

        // Attempt to authenticate the user with the provided credentials
        if (! Auth::attempt(['email' => $this->email, 'password' => $this->password], $this->remember)) {
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

        <flux:button href="{{ route('passwordless') }}" icon="envelope" class="w-full">
            Email me a sign-in code
        </flux:button>
    </form>
</div>

