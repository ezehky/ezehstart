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

    public User $user;

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
        $this->dispatch('attr', tag: $tag, title: $title, description: $description);
    }

    public function step1(): void
    {
        // Step one is the step that sends mail to whatever address was typed, so it
        // is the step the captcha guards. The code prompt after it is already
        // bounded by a six-digit code with an expiry.
        $this->validate($this->captchaRules([
            'email' => ['required', 'email', 'exists:users,email'],
        ]));

        // Get the user by email
        $this->user = User::query()->where('email', $this->email)->first();

        // If the user does not exist, respond with an error message
        $this->respondError('invalid credentials', ! $this->user, field: 'email');

        // Send the OTP to the user's email
        $this->sendOtp();

        $this->step = 2; // Move to the next step (OTP verification)
        $this->tag = 'Check your inbox';
        $this->title = 'Verification code sent';
        $this->description = 'Enter the six-digit code sent to '.Str::mask($this->user->email, '*', 2, 6).'. '.
        'Please check your inbox and enter the code to proceed with resetting your password.';
        $this->dispatchNext($this->tag, $this->title, $this->description);
        $this->resetValidation(); // Clear any previous validation errors
    }

    public function step2(): void
    {
        $this->validate(['otp' => ['required', 'digits:6']]);

        // The service counts the miss and destroys the code once the allowance is
        // spent, so a wrong code here is not something that can be retried forever.
        $this->respondError(
            'That reset code is invalid or has expired.',
            ! app(PasswordResetOtpService::class)->verify($this->email, $this->otp),
            fn () => $this->reset('otp'),
            'otp'
        );

        $this->step = 3; // Move to the next step (password reset)
        $this->tag = 'Reset your password';
        $this->title = 'Set a new password';
        $this->description = 'Enter your new password below to complete the password reset process.';
        $this->dispatchNext($this->tag, $this->title, $this->description);
        $this->resetValidation(); // Clear any previous validation errors
    }

    public function step3()
    {
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
        return to_route('login')->with('status', 'Your password has been reset.');

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
        session()->flash('status', 'A new verification code has been sent to your email address.');
        $this->resetValidation(); // Clear any previous validation errors
    }

    private function sendOtp(): void
    {
        // If the user does not exist, respond with an error message
        $this->respondError('invalid credentials', ! $this->user);

        $service = app(PasswordResetOtpService::class);

        // The resend floor. Without it the captcha on step one buys one pass at an
        // inbox and the resend button turns that into as much mail as anybody cares
        // to send, addressed to somebody who did not ask for any of it.
        $secondsRemaining = $service->secondsUntilResendFor($this->email);

        $this->respondError(
            "Please wait {$secondsRemaining} seconds before requesting another code.",
            $secondsRemaining > 0,
            field: $this->step === 1 ? 'email' : 'otp'
        );

        $service->send($this->user);
    }
};
?>

{{-- Start --}}
<x-slot:tag>
    <span x-data="{ tag: @js($tag) }" x-on:attr.window="tag = $event.detail.tag" x-html="tag"></span>
</x-slot:tag>
<x-slot:title>
    <span x-data="{ title: @js($title) }" x-on:attr.window="title = $event.detail.title" x-html="title"></span>
</x-slot:title>
<x-slot:description>
    <span x-data="{ description: @js($description) }" x-on:attr.window="description = $event.detail.description" x-html="description"></span>
</x-slot:description>
<x-slot:extra>
    <flux:text class="mt-8 text-center dark:text-slate-400">
        <flux:link href="{{ route('login') }}" variant="ghost">
            Back to sign in
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

            <flux:button type="submit" variant="primary">Verify Code</flux:button>
            <div class="flex justify-center gap-3">
                <flux:button type="button" wire:click="resendOtp" class="w-full">Resend code</flux:button>
                <flux:button type="button" wire:click="restart" variant="ghost" class="w-full">Start over</flux:button>
            </div>
        </form>
    @elseif ($step === 3)
        <form wire:submit.throttle.500ms="step3" class="mt-8 space-y-5">
            <x-form.password label="New password" wire:model="password" :note="$passwordNote" />
            <x-form.password label="Confirm new password" wire:model="password_confirmation" />

            <flux:button type="submit" variant="primary" class="w-full">Reset password</flux:button>
            <flux:button type="button" wire:click="restart" variant="ghost" class="w-full">Start over</flux:button>
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

            <flux:button type="submit" variant="primary" class="w-full">Send reset code</flux:button>
        </form>
    @endif
</div>
