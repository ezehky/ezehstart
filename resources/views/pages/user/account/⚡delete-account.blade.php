<?php

use App\Models\User;
use App\Services\AccountDeletionService;
use App\Services\AccountOtpService;
use App\Traits\WithAccountOtp;
use App\Traits\WithFormResponseMessage;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Component;

new class extends Component
{
    use WithAccountOtp, WithFormResponseMessage;

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
        $deletion = app(AccountDeletionService::class)->isEnabled() &&
            (bool) data_get($this->user->userProfile?->settings, 'can-delete-account', false);
        abort_unless($deletion, 404);

        kSetSiteTitle('profile', 'delete account');
    }

    /**
     * The account is inside its grace period, so the screen shows the countdown
     * and the way out rather than the confirmation form.
     */
    public function isPending(): bool
    {
        return app(AccountDeletionService::class)->isPending($this->user);
    }

    public function daysRemaining(): int
    {
        return app(AccountDeletionService::class)->daysRemaining($this->user);
    }

    public function graceDays(): int
    {
        return app(AccountDeletionService::class)->graceDays();
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

            $this->scheduleDeletion();

            return;
        }

        // No password on file (social-only account) — verify with an emailed OTP instead.
        $this->validate($this->confirmationRules(), attributes: ['confirmation_text' => 'confirmation']);

        $this->sendAccountOtp($this->user, 'delete-account', 'confirmation_text');

        $this->step = 2;
        $this->resetValidation();
    }

    public function resendOtp(): void
    {
        $this->sendAccountOtp($this->user, 'delete-account', 'otp');

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

        $this->scheduleDeletion();
    }

    /**
     * Start the grace period. Nothing is destroyed here and the account stays
     * signed in — the whole point of the delay is that somebody who changes
     * their mind can walk back in and say so.
     */
    protected function scheduleDeletion(): bool
    {
        $scheduledAt = app(AccountDeletionService::class)->schedule($this->user);

        $this->user->refresh();

        Flux::modal('confirm-delete-account')->close();
        $this->reset('step', 'password', 'confirmation_text', 'otp');

        return $this->respondSuccess(
            'Your account is scheduled for deletion on '.$scheduledAt->format('M d, Y').'. You can cancel any time before then.',
        );
    }

    /**
     * Change of mind, from the screen rather than from the emailed link.
     */
    public function cancelDeletion(): bool
    {
        $this->respondError('This account is not scheduled for deletion.', ! $this->isPending());

        app(AccountDeletionService::class)->cancel($this->user);

        $this->user->refresh();

        return $this->respondSuccess('Your account is no longer scheduled for deletion.');
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

    @if ($this->isPending())
        <flux:card class="space-y-6">
            <div>
                <flux:heading level="2" size="lg">Scheduled for deletion</flux:heading>
                <flux:text class="mt-1">
                    Your account is due to be deleted on
                    <strong>{{ $user->deletion_scheduled_at->format('M d, Y') }}</strong>.
                </flux:text>
            </div>

            <flux:callout color="amber" icon="clock">
                <flux:callout.heading>
                    @if ($this->daysRemaining() === 0)
                        This is your last day
                    @else
                        {{ $this->daysRemaining() }} {{ str()->plural('day', $this->daysRemaining()) }} left
                    @endif
                </flux:callout.heading>
                <flux:callout.text>
                    Nothing has been removed yet, and the account works exactly as it did before. We will email you
                    5 days and 1 day before the date arrives. Once it passes, your account and its data cannot be
                    recovered.
                </flux:callout.text>
            </flux:callout>

            <div class="flex justify-end">
                <flux:button variant="primary" icon="arrow-uturn-left" wire:click="cancelDeletion">
                    Keep my account
                </flux:button>
            </div>
        </flux:card>
    @else
        <flux:card class="space-y-6">
            <div>
                <flux:heading level="2" size="lg">Delete account</flux:heading>
                <flux:text class="mt-1">This action cannot be undone. Please review what happens carefully.</flux:text>
            </div>

            <flux:callout color="rose" icon="exclamation-triangle">
                <flux:callout.heading>This action is permanent</flux:callout.heading>
                <flux:callout.text>
                    Deleting your account starts a {{ $this->graceDays() }}-day countdown. Nothing is removed during
                    that time and you can cancel from this page or from the email we send you. Once the countdown ends,
                    all of your resources and data are permanently removed and this cannot be undone. Please make sure
                    you have downloaded any information you want to keep before continuing.
                </flux:callout.text>
            </flux:callout>

            <div class="flex justify-end">
                <flux:button variant="danger" icon="trash" wire:click="openConfirm">Delete my account</flux:button>
            </div>
        </flux:card>
    @endif

    <flux:modal name="confirm-delete-account" class="md:w-105">
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">Confirm account deletion</flux:heading>
                <flux:text class="mt-1">
                    Your account will be deleted on
                    {{ now()->addDays($this->graceDays())->format('M d, Y') }}.
                </flux:text>
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
