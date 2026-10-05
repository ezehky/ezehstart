<?php

use App\Enums\ActivityActionEnum;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\PasswordResetOtpService;
use App\Services\PasswordSecurityService;
use App\Traits\WithAuthWorker;
use App\Traits\WithCaptcha;
use App\Traits\WithPasswordTools;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::auth')] class extends Component
{
    use WithAuthWorker, WithCaptcha, WithPasswordTools;

    /**
     * Only ever set once a reset code has been verified — a code only exists for a
     * real account, so this is where the screen first learns there is one.
     */
    public ?User $user = null;

    public string $email;

    public string $otp;

    public string $password;

    public string $password_confirmation;

    public int $step = 1; // 1 = email, 2 = otp, 3 = reset password

    public string $tag = 'Password reset';

    public string $title = 'Get a reset code';

    public string $description = 'Enter your account email and get reset code.';

    public function mount()
    {
        kSetSiteTitle('Forgot password');
    }

    protected function dispatchNext(string $tag, string $title, string $description)
    {
        // Passed through __() here as well: restart() puts the English property
        // defaults back, and those are keys rather than copy.
        $this->dispatch('attr', tag: __($tag), title: __($title), description: __($description));
    }

    public function step1(): void
    {
        // Step one is the step that sends mail to whatever address was typed, so it
        // is the step the captcha guards. The code prompt after it is already
        // bounded by a six-digit code with an expiry.
        // Any well-formed address goes through, whether or not it has an account.
        // Saying which addresses are registered would hand anybody with a list a
        // way to sort it — so an unknown address, or a newsletter row with no
        // account behind it, sees exactly what a real one does and gets no mail.
        $this->validate($this->captchaRules([
            'email' => ['required', 'string', 'email', 'max:190'],
        ]));

        $this->sendOtp();

        $this->step = 2; // Move to the next step (OTP verification)
        $this->tag = __('Check your inbox');
        $this->title = __('Verification code sent');
        $this->description = __('If :email belongs to an account, a six-digit code is on its way. Please check your inbox and enter the code to proceed with resetting your password.', [
            'email' => Str::mask($this->email, '*', 2, 6),
        ]);
        $this->dispatchNext($this->tag, $this->title, $this->description);
        $this->resetValidation(); // Clear any previous validation errors
    }

    public function step2(): void
    {
        $this->validate(['otp' => ['required', 'digits:6']]);

        // The service counts the miss and destroys the code once the allowance is
        // spent, so a wrong code here is not something that can be retried forever.
        $this->respondError(
            __('That reset code is invalid or has expired.'),
            ! app(PasswordResetOtpService::class)->verify($this->email, $this->otp),
            fn () => $this->reset('otp'),
            'otp'
        );

        // A code verified, so the address has an account behind it — no code is
        // ever issued for one that does not.
        $this->user = User::query()->registered()->where('email', $this->email)->first();

        $this->respondError(__('That reset code is invalid or has expired.'), ! $this->user, field: 'otp');

        $this->step = 3; // Move to the next step (password reset)
        $this->tag = __('Reset your password');
        $this->title = __('Set a new password');
        $this->description = __('Enter your new password below to complete the password reset process.');
        $this->dispatchNext($this->tag, $this->title, $this->description);
        $this->resetValidation(); // Clear any previous validation errors
    }

    public function step3()
    {
        // Step two is the only thing that sets the account, so getting here without
        // one means the steps were skipped rather than walked.
        abort_if($this->user === null, 403);

        $this->validate(['password' => ['required', 'string', 'confirmed', $this->passwordStrengthRule()]]);

        // Checked after validation rather than as a rule, so somebody who typed a weak
        // password gets told it is weak before being told it is also old. A reset is
        // the same password write as the one on the security page and answers to the
        // same history — otherwise the route around history is to forget on purpose.
        $reuseError = $this->passwordReuseError($this->user, $this->password);

        $this->respondError($reuseError ?? '', $reuseError !== null, field: 'password');

        // Writes the new password and files the old hash away in one call — doing the
        // two separately is how history ends up with a gap in it.
        app(PasswordSecurityService::class)->updatePassword($this->user, $this->password);

        // Delete the password reset token from the database
        app(PasswordResetOtpService::class)->forget($this->email);

        // Log Activity: Log the password reset activity
        app(ActivityLogService::class)->logActivity(ActivityActionEnum::PASSWORD_CHANGE, $this->email);

        // Redirect the user to their respective dashboard based on their role
        return to_route('login')->with('status', __('Your password has been reset.'));

    }

    public function restart()
    {
        $email = $this->email; // Store the email before resetting

        // Reset all relevant properties to their initial state
        $this->reset(
            'user', 'email', 'otp', 'password', 'password_confirmation',
            'step', 'tag', 'title', 'description'
        );

        $this->dispatchNext($this->tag, $this->title, $this->description);

        // Reset the step to 1 (email input) and drop the outstanding code with it.
        app(PasswordResetOtpService::class)->forget($email);
    }

    public function resendOtp()
    {
        $this->sendOtp();
        session()->flash('status', __('If the address belongs to an account, a new code is on its way.'));
        $this->resetValidation(); // Clear any previous validation errors
    }

    private function sendOtp(): void
    {
        $service = app(PasswordResetOtpService::class);

        // The resend floor. Without it the captcha on step one buys one pass at an
        // inbox and the resend button turns that into as much mail as anybody cares
        // to send, addressed to somebody who did not ask for any of it.
        $secondsRemaining = $service->secondsUntilResendFor($this->email);

        $this->respondError(
            __('Please wait :seconds seconds before requesting another code.', ['seconds' => $secondsRemaining]),
            $secondsRemaining > 0,
            field: $this->step === 1 ? 'email' : 'otp'
        );

        $service->sendTo($this->email);
    }
};
?>

{{-- Start --}}
<x-slot:tag>
    <span x-data="{ tag: @js(__($tag)) }" x-on:attr.window="tag = $event.detail.tag" x-html="tag"></span>
</x-slot:tag>
<x-slot:title>
    <span x-data="{ title: @js(__($title)) }" x-on:attr.window="title = $event.detail.title" x-html="title"></span>
</x-slot:title>
<x-slot:description>
    <span x-data="{ description: @js(__($description)) }" x-on:attr.window="description = $event.detail.description" x-html="description"></span>
</x-slot:description>
<x-slot:extra>
    <flux:text class="mt-8 text-center dark:text-slate-400">
        <flux:link href="{{ route('login') }}" variant="ghost">
            {{ __('Back to sign in') }}
        </flux:link>
    </flux:text>
</x-slot:extra>
{{-- End of slot --}}

<div>
    @session('status')
        <flux:callout color="lime" class="mt-6 text-sm">{!! session('status') !!}</flux:callout>
    @endsession

    @if ($step === 2)
        <form wire:submit.throttle.500ms="step2" class="mt-8 space-y-5">
            <flux:otp wire:model="otp" length="6" />
            <flux:error name="otp" />

            <flux:button type="submit" variant="primary">{{ __('Verify code') }}</flux:button>
            <div class="flex justify-center gap-3">
                <flux:button type="button" wire:click="resendOtp" class="w-full">{{ __('Resend code') }}</flux:button>
                <flux:button type="button" wire:click="restart" variant="ghost" class="w-full">{{ __('Start over') }}</flux:button>
            </div>
        </form>
    @elseif ($step === 3)
        <form wire:submit.throttle.500ms="step3" class="mt-8 space-y-5">
            <x-form.password :label="__('New password')" wire:model="password" :note="$passwordNote" />
            <x-form.password :label="__('Confirm new password')" wire:model="password_confirmation" />

            <flux:button type="submit" variant="primary" class="w-full">{{ __('Reset password') }}</flux:button>
            <flux:button type="button" wire:click="restart" variant="ghost" class="w-full">{{ __('Start over') }}</flux:button>
        </form>
    @else
        <form wire:submit.throttle.500ms="step1" class="mt-8 space-y-5">
            <flux:input
                type="email"
                wire:model="email"
                autocomplete="email"
                autofocus
                placeholder="example@mail.com"
            />
            <flux:error name="email" />

            @if ($this->captchaRequired())
                <x-form.captcha action="password-reset" />
            @endif

            <flux:button type="submit" variant="primary" class="w-full">{{ __('Send reset code') }}</flux:button>
        </form>
    @endif
</div>
