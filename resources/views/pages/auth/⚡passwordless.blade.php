<?php

use App\Models\User;
use App\Rules\EmailRule;
use App\Services\PasswordlessOtpService;
use App\Traits\WithAuthWorker;
use App\Traits\WithCaptcha;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::auth')] class extends Component
{
    use WithAuthWorker, WithCaptcha;

    public string $email = '';

    public string $name = '';

    /**
     * Acceptance of the terms of service. Required before a new account is created,
     * exactly as on the password registration form.
     */
    public bool $agreed_to_terms = false;

    public string $otp = '';

    public string $timezone = '';

    /**
     * email = ask for the address, details = collect new account fields,
     * code = confirm the six digits we mailed.
     */
    public string $step = 'email';

    /**
     * Whether a verified code will create an account or sign an existing one in.
     */
    public bool $isNewAccount = false;

    public string $tag = 'No password needed';

    public string $title = 'Sign in with an email code';

    public string $description = 'Enter your email address and we will send you a six-digit code. New here? We will set your account up in the same flow.';

    public function mount(): void
    {
        // The switch has to close the route, not just hide the button on the login
        // page. A sign-in route left reachable behind a hidden link is not a
        // disabled feature.
        abort_unless((bool) kSiteFlag('security', 'passwordless-login', true), 404);

        kSetSiteTitle('Passwordless sign in');
    }

    /**
     * Step one: work out whether this address already has an account, and route the
     * visitor to a code prompt or to the account details they still owe us.
     */
    public function submitEmail(): void
    {
        // The captcha sits on this step rather than on the code prompt: this is the
        // one that puts mail in somebody else's inbox, and an address typed here
        // does not have to belong to whoever typed it.
        $this->validate($this->captchaRules(['email' => ['required', 'email']]));

        $user = User::query()->whereEmail($this->email)->first();

        // Unknown address: the account details have to be collected before a code is
        // issued, so acceptance of the policies is on record before anything exists.
        if (! $user) {
            $this->isNewAccount = true;

            $this->moveTo(
                'details',
                'Start your journey',
                'Create your account',
                "Tell us who you are and we will email a code to confirm {$this->email}. No password to remember.",
            );

            return;
        }

        $this->guardAccountCanUseCodes($user);

        $this->isNewAccount = false;
        $this->name = $user->name;

        $this->sendCode();
        $this->moveToCodeStep();
    }

    /**
     * Step two, new accounts only: validate the details, then mail the code that
     * both confirms the address and creates the account.
     */
    public function submitDetails(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:50',
                Rule::unique('users', 'email'),
                new EmailRule,
            ],
            'agreed_to_terms' => ['accepted'],
        ]);

        $this->sendCode();
        $this->moveToCodeStep();
    }

    /**
     * Final step: the code proves the visitor controls the mailbox, which is all the
     * proof either signing in or registering needs.
     */
    public function verify()
    {
        $this->validate(['otp' => ['required', 'digits:6']]);

        $this->respondError(
            'That code is invalid or has expired.',
            ! app(PasswordlessOtpService::class)->verify($this->email, $this->otp),
            fn () => $this->reset('otp'),
            'otp'
        );

        return $this->isNewAccount
            ? $this->createPasswordlessAccount()
            : $this->signInExistingUser();
    }

    public function resendCode(): void
    {
        $this->sendCode();

        session()->flash('status', 'A new code has been sent to your email address.');

        $this->resetValidation();
    }

    public function startOver(): void
    {
        // Drop the outstanding code so an abandoned one cannot be used later.
        app(PasswordlessOtpService::class)->forget($this->email);

        $this->reset(
            'email', 'name', 'agreed_to_terms',
            'otp', 'step', 'isNewAccount', 'tag', 'title', 'description'
        );

        $this->resetValidation();
        $this->dispatchAttributes();
    }

    protected function messages(): array
    {
        return [
            'agreed_to_terms.accepted' => 'Please accept our policies to create an account.',
        ];
    }

    private function signInExistingUser()
    {
        $user = User::query()->whereEmail($this->email)->first();

        $this->respondError('We could not find that account.', ! $user, field: 'otp');

        // The account may have been suspended or promoted while the code was in
        // flight, so the eligibility checks run again before the session is granted.
        $this->guardAccountCanUseCodes($user, field: 'otp');

        // Receiving the code proves the address, so an account still sitting
        // unverified is verified here instead of being sent through the OTP page.
        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        Auth::login($user, true);

        $this->loginUser('Signed in with an email code.');

        return $this->userDashboardRedirect();
    }

    private function createPasswordlessAccount()
    {
        $user = $this->createUser(
            [
                'name' => $this->name,
                'email' => $this->email,
                // The code was delivered to this address, so it is already proven and
                // the account skips the verification page entirely.
                'email_verified_at' => now(),
                'timezone' => $this->timezone ?: session()->get('user-timezone', config('app.timezone')),
            ],
            sendOtp: false,
        );

        $this->respondError(
            message: 'Failed to create user. Please try again.',
            if: ! $user
        );

        return $this->userDashboardRedirect();
    }

    /**
     * Suspended accounts get nothing, and privileged accounts keep their password:
     * an admin session should never be obtainable from mailbox access alone.
     */
    private function guardAccountCanUseCodes(User $user, string $field = 'email'): void
    {
        $this->respondError(
            'Login Access Denied. '.$user->status->message(),
            ! $user->status->isActive(),
            field: $field
        );

        $this->respondError(
            'This account signs in with its password. Please use the sign in form.',
            $user->isAdmin(),
            field: $field
        );
    }

    private function sendCode(): void
    {
        $service = app(PasswordlessOtpService::class);
        $secondsRemaining = $service->secondsUntilResendFor($this->email);

        $this->respondError(
            "Please wait {$secondsRemaining} seconds before requesting another code.",
            $secondsRemaining > 0,
            field: $this->step === 'code' ? 'otp' : 'email'
        );

        $service->send($this->email, $this->name ?: 'there', $this->isNewAccount);
    }

    private function moveToCodeStep(): void
    {
        $this->moveTo(
            'code',
            'Check your inbox',
            $this->isNewAccount ? 'Confirm your email' : 'Enter your code',
            'Enter the six-digit code sent to '.Str::mask($this->email, '*', 2, 6).
            '. It expires in '.PasswordlessOtpService::EXPIRATION_MINUTES.' minutes.',
        );
    }

    private function moveTo(string $step, string $tag, string $title, string $description): void
    {
        $this->step = $step;
        $this->tag = $tag;
        $this->title = $title;
        $this->description = $description;

        $this->resetValidation();
        $this->dispatchAttributes();
    }

    /**
     * The heading lives in the layout's slots, outside this component's root element,
     * so Livewire cannot re-render it. Alpine picks the new copy up instead.
     */
    private function dispatchAttributes(): void
    {
        $this->dispatch('attr', tag: $this->tag, title: $this->title, description: $this->description);
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
        Prefer a password?
        <flux:link href="{{ route('login') }}" variant="ghost">
            Sign in the usual way
        </flux:link>
    </flux:text>
</x-slot:extra>
{{-- End of slot --}}

<div>
    @session('status')
        <flux:callout color="lime" class="mt-6 text-sm">{!! session('status') !!}</flux:callout>
    @endsession

    @if ($step === 'code')
        <form wire:submit.throttle.500ms="verify" class="mt-8 space-y-5">
            <flux:otp wire:model="otp" length="6" />
            <flux:error name="otp" />

            <flux:button type="submit" variant="primary" icon="check" class="w-full">
                {{ $isNewAccount ? 'Confirm and create account' : 'Sign in' }}
            </flux:button>

            <div class="flex justify-center gap-3">
                <flux:button type="button" wire:click="resendCode" class="w-full">Resend code</flux:button>
                <flux:button type="button" wire:click="startOver" variant="ghost" class="w-full">Start over</flux:button>
            </div>
        </form>
    @elseif ($step === 'details')
        <form wire:submit.throttle.500ms="submitDetails" class="mt-8 space-y-5">
            <flux:input wire:model="name" autocomplete="name" autofocus placeholder="Enter your full name" />
            <flux:error name="name" />

            <x-form.consent-field />

            <flux:error name="email" />

            <flux:button type="submit" variant="primary" class="w-full">Email me a code</flux:button>
            <flux:button type="button" wire:click="startOver" variant="ghost" class="w-full">Use a different email</flux:button>
        </form>
    @else
        <form wire:submit.throttle.500ms="submitEmail" class="mt-8 space-y-5">
            <flux:input
                label="Email address"
                wire:model="email"
                type="email"
                autocomplete="email"
                autofocus
                placeholder="you@example.com"
                icon="envelope"
            />
            <flux:error name="email" />

            @if ($this->captchaRequired())
                <x-form.captcha action="passwordless" />
            @endif

            <flux:button type="submit" variant="primary" class="w-full">Send me a code</flux:button>
        </form>
    @endif

    @script
        <script>
            const timezone = new Intl.DateTimeFormat().resolvedOptions().timeZone;
            $wire.timezone = timezone;

            fetch('/set-timezone', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                },
                body: JSON.stringify({ timezone })
            });
        </script>
    @endscript
</div>
