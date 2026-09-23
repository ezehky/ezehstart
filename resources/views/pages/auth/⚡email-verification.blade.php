<?php

use App\Models\User;
use App\Services\EmailVerificationOtpService;
use App\Traits\WithAccountOtp;
use App\Traits\WithFormResponseMessage;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::auth')] class extends Component
{
    use WithAccountOtp, WithFormResponseMessage;

    public User $user;

    public string $otp = '';

    public function mount(): void
    {
        // If the user has already verified their email, redirect them to the dashboard
        if ($this->user->hasVerifiedEmail()) {
            $this->redirectRoute('user.dashboard');

            return;
        }

        kSetSiteTitle('Email Verification');

        // If the "send" query parameter is present, resend the verification code
        if (session()->has('send') || request()->query->has('send')) {
            $this->resend();
        }
    }

    public function verify(): bool
    {
        $this->validate([
            'otp' => ['required', 'digits:6'],
        ]);

        $this->respondError(
            __('That verification code is invalid or has expired.'),
            ! app(EmailVerificationOtpService::class)->verify($this->user, $this->otp),
            field: 'otp'
        );

        //
        $this->redirectRoute('user.dashboard');

        // Mark the user's email as verified
        return $this->respondSuccess(__('Your email address has been verified.'));
    }

    public function resend(): void
    {
        if ($this->user->hasVerifiedEmail()) {
            session()->flash('status', __('Your email address is already verified.'));

            return;
        }

        $this->sendVerificationOtp($this->user, 'otp');

        session()->flash('status', __('We sent a new verification code to your email address.'));
    }
};
?>

<x-slot:tag>{{ __('Check your inbox') }}</x-slot:tag>
<x-slot:title>{{ __('Verify your email') }}</x-slot:title>
<x-slot:description>{{ __('Enter the six-digit code sent to :email.', ['email' => Str::mask($user->email, '*', 2, 6)]) }}</x-slot:description>

<div>
    @session('status')
        <flux:callout color="lime" class="mt-6 text-sm">{!! session('status') !!}</flux:callout>
    @endsession

    <form wire:submit.throttle.500ms="verify" class="mt-8 space-y-5">
        <flux:otp wire:model="otp" length="6" />
        <flux:error name="otp" />

        <div class="flex items-center gap-3">
            <flux:button type="submit" variant="primary" icon="check">
                {{ __('Verify email') }}
            </flux:button>

            <flux:button type="button" wire:click="resend">
                {{ __('Resend code') }}
            </flux:button>
        </div>
    </form>
</div>
