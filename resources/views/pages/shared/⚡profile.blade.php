<?php

use App\Enums\ActivityActionEnum;
use App\Models\User;
use App\Services\AccountOtpService;
use App\Services\ActivityLogService;
use App\Traits\WithFormResponseMessage;
use App\Traits\WithPasswordTools;
use Livewire\Component;

new class extends Component
{
    use WithFormResponseMessage, WithPasswordTools;

    public User $user;

    public int $passwordStep = 1; // 1 = current password, 2 = otp, 3 = new password

    public string $current_password = '';

    public string $password_otp = '';

    public string $new_password = '';

    public string $new_password_confirmation = '';

    public function mount(): void
    {
        $this->user = auth()->user();

        kSetSiteTitle('profile');
    }

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
};
?>

<div class="mx-auto max-w-3xl space-y-6">
    <div>
        <flux:heading level="1" size="xl">My profile</flux:heading>
        <flux:text class="mt-1">Your account details. Contact an administrator to update any of this information.</flux:text>
    </div>

    @session('status')
        <flux:callout color="lime" class="text-sm">{{ session('status') }}</flux:callout>
    @endsession

    {{-- Read-only account details --}}
    <flux:card class="space-y-6">
        <div class="flex items-center gap-4">
            <div class="grid size-20 shrink-0 place-items-center overflow-hidden rounded-full bg-slate-100 ring-2 ring-white dark:bg-slate-800 dark:ring-slate-700">
                <img src="{{ kSafeImage($user->avatar, 'user') }}" alt="{{ $user->name }}" class="size-full object-cover" />
            </div>
            <div>
                <flux:heading size="lg">{{ $user->name }}</flux:heading>
                <div class="mt-1 flex flex-wrap gap-1.5">
                    <flux:badge size="sm" :color="$user->type->isAdmin() ? 'lime' : 'sky'">
                        {{ $user->type->label() }}
                    </flux:badge>
                    @if ($user->role)
                        <flux:badge size="sm" color="zinc">{{ $user->role->name }}</flux:badge>
                    @endif
                </div>
            </div>
        </div>

        <flux:separator variant="subtle" />

        <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
            <flux:input value="{{ $user->name }}" label="Full name" readonly disabled />
            <flux:input value="{{ $user->email }}" label="Email address" readonly disabled />
            <flux:input value="{{ $user->phone_number ?: '—' }}" label="Phone number" readonly disabled />
            <flux:input value="{{ $user->createdAtHuman() }}" label="Created" readonly disabled />
        </div>
    </flux:card>

    {{-- Change password --}}
    <flux:card class="space-y-6">
        <div>
            <flux:heading level="2" size="lg">
                {{ $user->password ? 'Change password' : 'Set a password' }}
            </flux:heading>
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
</div>
