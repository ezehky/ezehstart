<?php

use App\Enums\ActivityActionEnum;
use App\Models\User;
use App\Services\AccountOtpService;
use App\Services\ActivityLogService;
use App\Traits\WithFormResponseMessage;
use App\Traits\WithPasswordTools;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Jenssegers\Agent\Facades\Agent;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    use WithFormResponseMessage, WithPasswordTools;

    public User $user;

    // Password change
    public int $passwordStep = 1; // 1 = current password, 2 = otp, 3 = new password

    public string $current_password = '';

    public string $password_otp = '';

    public string $new_password = '';

    public string $new_password_confirmation = '';

    // Email change
    public int $emailStep = 1; // 1 = new email, 2 = otp

    public string $new_email = '';

    public string $email_otp = '';

    public function mount(): void
    {
        $this->user = auth()->user();

        kSetSiteTitle('profile', 'security');
    }

    // |||||||||||||||||||||||||||||||||||||||||||||||||||
    // Password change

    public function passwordStep1(): void
    {
        // Social-only accounts have no password yet — nothing to verify, so skip straight to the OTP.
        if ($this->user->password) {
            $this->validate(['current_password' => ['required', 'string', 'current_password']]);
        }

        app(AccountOtpService::class)->send($this->user, 'change-password');

        $this->passwordStep = 2;
        $this->resetValidation();
    }

    public function passwordResendOtp(): void
    {
        app(AccountOtpService::class)->send($this->user, 'change-password');

        session()->flash('status', 'A new verification code has been sent to your email address.');
    }

    public function passwordStep2(): void
    {
        $this->validate(['password_otp' => ['required', 'digits:6']]);

        $this->respondError(
            'That verification code is invalid or has expired.',
            ! app(AccountOtpService::class)->verify($this->user, 'change-password', $this->password_otp),
            fn () => $this->reset('password_otp'),
            'password_otp'
        );

        $this->passwordStep = 3;
        $this->resetValidation();
    }

    public function passwordStep3(): void
    {
        $this->validate([
            'new_password' => ['required', 'string', 'confirmed', $this->passwordStrengthRule()],
        ]);

        $this->user->forceFill(['password' => $this->new_password])->save();

        app(ActivityLogService::class)->logActivity(ActivityActionEnum::PASSWORD_CHANGE, model: $this->user);

        $this->reset('passwordStep', 'current_password', 'password_otp', 'new_password', 'new_password_confirmation');

        $this->respondSuccess('Your password has been changed.');
    }

    public function passwordRestart(): void
    {
        $this->reset('passwordStep', 'current_password', 'password_otp', 'new_password', 'new_password_confirmation');
    }

    // |||||||||||||||||||||||||||||||||||||||||||||||||||
    // Email change

    public function emailStep1(): void
    {
        $this->validate([
            'new_email' => ['required', 'email', 'max:50', 'unique:users,email'],
        ]);

        $this->respondError(
            'That is already your current email address.',
            kTextCompare($this->new_email, $this->user->email),
            field: 'new_email'
        );

        app(AccountOtpService::class)->send($this->user, 'change-email');

        $this->emailStep = 2;
        $this->resetValidation();
    }

    public function emailResendOtp(): void
    {
        app(AccountOtpService::class)->send($this->user, 'change-email');

        session()->flash('status', 'A new verification code has been sent to your current email address.');
    }

    public function emailStep2()
    {
        $this->validate(['email_otp' => ['required', 'digits:6']]);

        $this->respondError(
            'That verification code is invalid or has expired.',
            ! app(AccountOtpService::class)->verify($this->user, 'change-email', $this->email_otp),
            fn () => $this->reset('email_otp'),
            'email_otp'
        );

        $newEmail = $this->new_email;

        $this->user->forceFill([
            'email' => $newEmail,
            'email_verified_at' => null,
        ])->save();

        app(ActivityLogService::class)->logActivity(ActivityActionEnum::EMAIL_CHANGE, $newEmail, model: $this->user);

        $this->reset('emailStep', 'new_email', 'email_otp');

        return $this->redirectRoute('email.verification', ['user' => $newEmail, 'send' => true]);
    }

    public function emailRestart(): void
    {
        $this->reset('emailStep', 'new_email', 'email_otp');
    }

    // |||||||||||||||||||||||||||||||||||||||||||||||||||
    // Login sessions

    #[Computed]
    public function sessions(): Collection
    {
        return DB::table('sessions')->where('user_id', $this->user->id)->orderByDesc('last_activity')->get()
            ->map(fn ($row) => [
                'id' => $row->id,
                'is_current' => $row->id === session()->getId(),
                'ip_address' => $row->ip_address,
                'browser' => Agent::browser($row->user_agent),
                'platform' => Agent::platform($row->user_agent),
                'last_active' => Carbon::createFromTimestamp($row->last_activity)->diffForHumans(),
            ]);
    }

    public function logoutSession(string $sessionId): void
    {
        if ($sessionId === session()->getId()) {
            return;
        }

        DB::table('sessions')->where('user_id', $this->user->id)->where('id', $sessionId)->delete();

        app(ActivityLogService::class)->logActivity(ActivityActionEnum::SESSION_LOGOUT_OTHERS, model: $this->user);

        unset($this->sessions);

        $this->respondSuccess('That session has been logged out.');
    }

    public function logoutOtherSessions(): void
    {
        DB::table('sessions')
            ->where('user_id', $this->user->id)
            ->where('id', '!=', session()->getId())
            ->delete();

        app(ActivityLogService::class)->logActivity(ActivityActionEnum::SESSION_LOGOUT_OTHERS, model: $this->user);

        unset($this->sessions);

        $this->respondSuccess('You have been logged out of all other sessions.');
    }
};
?>

<div class="mx-auto max-w-3xl space-y-6">
    <x-dashboard.tab-nav
        active="security-settings"
        isUser
        title="Security settings"
        subtitle="Manage your security settings and active sessions."
    />

    @session('status')
        <flux:callout color="lime" class="text-sm">{{ session('status') }}</flux:callout>
    @endsession

    {{-- Change password --}}
    <flux:card class="space-y-6">
        <div>
            <flux:heading level="2" size="lg">{{ $user->password ? 'Change password' : 'Set a password' }}</flux:heading>
            <flux:text class="mt-1">
                @if ($user->password)
                    For your security, we'll email you a verification code before saving a new password.
                @else
                    You currently sign in with a connected account only. Add a password as a backup sign-in method —
                    we'll email you a verification code first.
                @endif
            </flux:text>
        </div>

        @if ($passwordStep === 1)
            <form wire:submit="passwordStep1" class="max-w-sm space-y-4">
                @if ($user->password)
                    <x-form.password label="Current password" wire:model="current_password" />
                @endif
                <flux:button type="submit" variant="primary">Continue</flux:button>
            </form>
        @elseif ($passwordStep === 2)
            <form wire:submit="passwordStep2" class="max-w-sm space-y-4">
                <flux:text size="sm">
                    Enter the six-digit code sent to {{ Str::mask($user->email, '*', 2, 6) }}.
                </flux:text>
                <flux:otp wire:model="password_otp" length="6" />
                <flux:error name="password_otp" />
                <div class="flex gap-3">
                    <flux:button type="submit" variant="primary">Verify code</flux:button>
                    <flux:button type="button" wire:click="passwordResendOtp">Resend</flux:button>
                    <flux:button type="button" variant="ghost" wire:click="passwordRestart">Cancel</flux:button>
                </div>
            </form>
        @else
            <form wire:submit="passwordStep3" class="max-w-sm space-y-4">
                <x-form.password label="New password" wire:model="new_password" />
                <flux:text class="text-xs">{!! $passwordNote !!}</flux:text>
                <x-form.password label="Confirm new password" wire:model="new_password_confirmation" />
                <div class="flex gap-3">
                    <flux:button type="submit" variant="primary">Save new password</flux:button>
                    <flux:button type="button" variant="ghost" wire:click="passwordRestart">Cancel</flux:button>
                </div>
            </form>
        @endif
    </flux:card>

    {{-- Change email --}}
    <flux:card class="space-y-6">
        <div>
            <flux:heading level="2" size="lg">Change email address</flux:heading>
            <flux:text class="mt-1">
                Currently <span class="font-medium text-slate-950 dark:text-white">{{ $user->email }}</span>.
                We'll verify it's you before making the change.
            </flux:text>
        </div>

        @if ($emailStep === 1)
            <form wire:submit="emailStep1" class="max-w-sm space-y-4">
                <flux:input type="email" wire:model="new_email" label="New email address" placeholder="you@example.com" />
                <flux:button type="submit" variant="primary">Continue</flux:button>
            </form>
        @else
            <form wire:submit="emailStep2" class="max-w-sm space-y-4">
                <flux:text size="sm">
                    Enter the six-digit code sent to {{ Str::mask($user->email, '*', 2, 6) }} to confirm the change to
                    {{ $new_email }}.
                </flux:text>
                <flux:otp wire:model="email_otp" length="6" />
                <flux:error name="email_otp" />
                <div class="flex gap-3">
                    <flux:button type="submit" variant="primary">Verify &amp; update</flux:button>
                    <flux:button type="button" wire:click="emailResendOtp">Resend</flux:button>
                    <flux:button type="button" variant="ghost" wire:click="emailRestart">Cancel</flux:button>
                </div>
            </form>
        @endif
    </flux:card>

    {{-- Login sessions --}}
    <flux:card class="space-y-4">
        <div class="flex items-center justify-between">
            <div>
                <flux:heading level="2" size="lg">Login sessions</flux:heading>
                <flux:text class="mt-1">Devices currently signed in to your account.</flux:text>
            </div>
            @if ($this->sessions->count() > 1)
                <flux:button size="sm" variant="danger" x-on:click="$flux.modal('sessionsModal').show()">
                    Log out other sessions
                </flux:button>
            @endif
        </div>

        <ul class="divide-y divide-slate-100 dark:divide-slate-800">
            @foreach ($this->sessions as $row)
                <li wire:key="session-{{ $row['id'] }}" class="flex items-center justify-between gap-3 py-3 first:pt-0 last:pb-0">
                    <div class="flex items-center gap-3">
                        <x-dashboard.icon-box size="sm" icon="computer-desktop" :tone="$row['is_current'] ? 'emerald' : 'slate'" />
                        <div>
                            <p class="text-sm font-semibold text-slate-950 dark:text-white">
                                {{ $row['platform'] }} · {{ $row['browser'] }}
                                @if ($row['is_current'])
                                    <flux:badge size="sm" color="lime" inset="top bottom">This device</flux:badge>
                                @endif
                            </p>
                            <p class="text-xs text-slate-500 dark:text-slate-400">
                                {{ $row['ip_address'] }} · Active {{ $row['last_active'] }}
                            </p>
                        </div>
                    </div>
                    @if (! $row['is_current'])
                        <flux:button size="sm" variant="ghost" wire:click="logoutSession('{{ $row['id'] }}')">
                            Log out
                        </flux:button>
                    @endif
                </li>
            @endforeach
        </ul>
    </flux:card>

    <x-dashboard.confirm-modal
        name="sessionsModal"
        title="Log out of all other sessions?"
        icon="computer-desktop"
        confirm="Log the others out"
        cancel="Leave them signed in"
        wire:click="logoutOtherSessions"
    >
        Every device signed in to this account apart from the one you are using now is signed
        out and has to sign in again. This device stays signed in.
    </x-dashboard.confirm-modal>
</div>
