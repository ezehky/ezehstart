<?php

use App\Models\User;
use App\Services\TwoFactorService;
use App\Traits\WithAuthWorker;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::auth')] class extends Component
{
    use WithAuthWorker;

    public string $code = '';

    public string $recovery_code = '';

    /**
     * Whether the recovery-code form is showing instead of the six-digit one.
     */
    public bool $useRecovery = false;

    public function mount()
    {
        // Reaching this page without having passed the first factor is either a
        // stale tab or somebody trying it on. Either way there is nothing here.
        if (! session()->has(self::TWO_FACTOR_SESSION_KEY)) {
            return $this->redirectRoute('login', navigate: true);
        }

        kSetSiteTitle('two-factor');
    }

    protected function rules(): array
    {
        return $this->useRecovery
            ? ['recovery_code' => ['required', 'string', 'min:8']]
            : ['code' => ['required', 'digits:6']];
    }

    public function toggleRecovery(): void
    {
        $this->useRecovery = ! $this->useRecovery;

        $this->reset('code', 'recovery_code');
        $this->resetValidation();
    }

    public function verify()
    {
        $this->validate();

        $user = $this->challengedUser();

        // The account went away — deleted or suspended — between the two steps.
        if (! $user) {
            session()->forget([self::TWO_FACTOR_SESSION_KEY, self::TWO_FACTOR_REMEMBER_KEY]);

            return $this->redirectRoute('login', navigate: true);
        }

        // Throttled on the account id rather than the email, which is not asked
        // for on this screen. Without it the second factor is six digits with
        // unlimited guesses, which is not a second factor.
        $this->ensureIsNotRateLimited((string) $user->id, $this->useRecovery ? 'recovery_code' : 'code', 'two-factor');

        $service = app(TwoFactorService::class);

        $passed = $this->useRecovery
            ? $service->useRecoveryCode($user, $this->recovery_code)
            : $service->verifyCode($user->twoFactor, $this->code);

        if (! $passed) {
            $this->recordFailedAttempt((string) $user->id, 'two-factor');

            $this->respondError(
                $this->useRecovery
                    ? 'That recovery code is not valid, or has already been used.'
                    : 'That code is not valid. Check your authenticator app and try again.',
                true,
                fn () => $this->reset('code', 'recovery_code'),
                $this->useRecovery ? 'recovery_code' : 'code'
            );
        }

        $this->clearRateLimit((string) $user->id, 'two-factor');

        $remember = (bool) session()->pull(self::TWO_FACTOR_REMEMBER_KEY, false);

        session()->forget(self::TWO_FACTOR_SESSION_KEY);

        Auth::login($user, $remember);

        $this->loginUser('Signed in with two-factor authentication.');

        // Remembering the device is tied to "keep me signed in": somebody who did
        // not ask to stay signed in has not asked to skip the prompt either.
        if ($remember && $token = $service->rememberDevice($user)) {
            Cookie::queue(
                $service->rememberCookieName(),
                $token,
                $service->rememberDays() * 24 * 60
            );
        }

        return $this->userDashboardRedirect();
    }

    private function challengedUser(): ?User
    {
        $id = session()->get(self::TWO_FACTOR_SESSION_KEY);

        return $id ? User::query()->find($id) : null;
    }
};
?>

<x-slot:tag>One more step</x-slot:tag>
<x-slot:title>Two-factor authentication</x-slot:title>
<x-slot:description>
    {{ $useRecovery
        ? 'Enter one of the recovery codes you saved when you turned this on.'
        : 'Enter the six-digit code from your authenticator app.' }}
</x-slot:description>
<x-slot:extra>
    <flux:text class="mt-8 text-center dark:text-slate-400">
        <flux:link href="{{ route('logout') }}" variant="ghost">
            Sign in as someone else
        </flux:link>
    </flux:text>
</x-slot:extra>

<div>
    <form wire:submit="verify" class="mt-8 space-y-5">
        @if ($useRecovery)
            <flux:input
                label="Recovery code"
                wire:model="recovery_code"
                autofocus
                placeholder="XXXXX-XXXXX"
                icon="key"
            />
        @else
            <flux:field>
                <flux:label>Authentication code</flux:label>
                <flux:otp wire:model="code" length="6" autofocus />
                <flux:error name="code" />
            </flux:field>
        @endif

        <flux:button type="submit" variant="primary" class="w-full">Verify</flux:button>

        <flux:button type="button" variant="ghost" class="w-full" wire:click="toggleRecovery">
            {{ $useRecovery ? 'Use my authenticator app instead' : 'I lost my device — use a recovery code' }}
        </flux:button>
    </form>
</div>
