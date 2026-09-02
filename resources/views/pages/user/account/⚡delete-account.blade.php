<?php

use App\Models\User;
use App\Services\AccountDeletionService;
use App\Services\AccountOtpService;
use App\Traits\WithFormResponseMessage;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Component;

new class extends Component
{
    use WithFormResponseMessage;

    public User $user;

    public int $step = 1; // 1 = confirm (password or request otp), 2 = otp (password-less accounts only)

    public string $password = '';

    public string $confirmation_text = '';

    public string $otp = '';

    public function mount(): void
    {
        $this->user = auth()->user();
        $this->user->load('userProfile:id,user_id,settings');

        // An account that has never opened its dashboard has no profile row yet, so
        // the switch is read null-safely rather than assumed to exist.
        $deletion = kSiteConfig('user.account-deletion', default: false) &&
            (bool) data_get($this->user->userProfile?->settings, 'can-delete-account', false);
        abort_unless($deletion, 404);

        kSetSiteTitle('profile', 'delete account');
    }

    public function openConfirm(): void
    {
        $this->reset('step', 'password', 'confirmation_text', 'otp');

        Flux::modal('confirm-delete-account')->show();
    }

    protected function confirmationRules(): array
    {
        return [
            'confirmation_text' => ['required', Rule::in(['DELETE'])],
        ];
    }

    public function step1(): void
    {
        if ($this->user->password) {
            $this->validate([
                ...$this->confirmationRules(),
                'password' => ['required', 'string', 'current_password'],
            ], attributes: ['confirmation_text' => 'confirmation']);

            $this->performDeletion();

            return;
        }

        // No password on file (social-only account) — verify with an emailed OTP instead.
        $this->validate($this->confirmationRules(), attributes: ['confirmation_text' => 'confirmation']);

        app(AccountOtpService::class)->send($this->user, 'delete-account');

        $this->step = 2;
        $this->resetValidation();
    }

    public function resendOtp(): void
    {
        app(AccountOtpService::class)->send($this->user, 'delete-account');

        session()->flash('status', 'A new verification code has been sent to your email address.');
    }

    public function step2(): void
    {
        $this->validate(['otp' => ['required', 'digits:6']]);

        $this->respondError(
            'That verification code is invalid or has expired.',
            ! app(AccountOtpService::class)->verify($this->user, 'delete-account', $this->otp),
            fn () => $this->reset('otp'),
            'otp'
        );

        $this->performDeletion();
    }

    protected function performDeletion()
    {
        $service = app(AccountDeletionService::class);

        if ($service->hasSignificantActivity($this->user)) {
            $service->anonymize($this->user);
        } else {
            $service->hardDelete($this->user);
        }

        // Log out directly rather than via UserService::logoutUser() — the user row
        // may have just been hard-deleted, and that helper also writes an activity
        // log entry that would violate the users FK if the row no longer exists.
        Auth::logout();
        session()->invalidate();
        session()->regenerateToken();

        return to_route('home')->with('status', 'Your account has been deleted. We\'re sorry to see you go.');
    }
};
?>

<div class="mx-auto max-w-3xl space-y-6">
    <x-dashboard.tab-nav
        active="delete-account"
        isUser
        title="Delete account"
        subtitle="Permanently delete your account and personal data."
    />

    <flux:card class="space-y-6">
        <div>
            <flux:heading level="2" size="lg">Delete account</flux:heading>
            <flux:text class="mt-1">This action cannot be undone. Please review what happens carefully.</flux:text>
        </div>

        <flux:callout color="rose" icon="exclamation-triangle">
            <flux:callout.heading>This action is permanent</flux:callout.heading>
            <flux:callout.text>
                Once your account is deleted, all of its resources and data will be permanently removed. This action
                cannot be undone. Please make sure you have downloaded any information you want to keep before continuing.
            </flux:callout.text>
        </flux:callout>

        <div class="flex justify-end">
            <flux:button variant="danger" icon="trash" wire:click="openConfirm">Delete my account</flux:button>
        </div>
    </flux:card>

    <flux:modal name="confirm-delete-account" class="md:w-105">
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">Confirm account deletion</flux:heading>
                <flux:text class="mt-1">This step can't be reversed.</flux:text>
            </div>

            @session('status')
                <flux:callout color="lime" class="text-sm">{{ session('status') }}</flux:callout>
            @endsession

            @if ($step === 1)
                <form wire:submit="step1" class="space-y-4">
                    @if ($user->password)
                        <x-form.password label="Current password" wire:model="password" />
                    @endif

                    <flux:input
                        wire:model="confirmation_text"
                        label='Type "DELETE" to confirm'
                        placeholder="DELETE"
                    />

                    <div class="flex justify-end gap-3">
                        <flux:modal.close>
                            <flux:button variant="ghost">Cancel</flux:button>
                        </flux:modal.close>
                        <flux:button type="submit" variant="danger">Delete my account</flux:button>
                    </div>
                </form>
            @else
                <form wire:submit="step2" class="space-y-4">
                    <flux:text size="sm">Enter the six-digit code sent to your email to finish deleting your account.</flux:text>
                    <flux:otp wire:model="otp" length="6" />
                    <flux:error name="otp" />

                    <div class="flex justify-end gap-3">
                        <flux:button type="button" wire:click="resendOtp">Resend code</flux:button>
                        <flux:button type="submit" variant="danger">Confirm deletion</flux:button>
                    </div>
                </form>
            @endif
        </div>
    </flux:modal>
</div>
