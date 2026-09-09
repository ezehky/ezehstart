<?php

use App\Rules\EmailRule;
use App\Traits\WithAuthWorker;
use App\Traits\WithPasswordTools;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::auth')] class extends Component
{
    use WithAuthWorker, WithPasswordTools;

    public string $name;

    public string $email;

    public string $password;

    public string $password_confirmation;

    public string $timezone = '';

    /**
     * Acceptance of the terms of service. Required — an account cannot be created
     * without it.
     */
    public bool $agreed_to_terms = false;

    public function mount(): void
    {
        kSetSiteTitle('Register');
    }

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:50',
                Rule::unique('users', 'email'),
                new EmailRule,
            ],
            'password' => [
                'required',
                'string',
                'confirmed',
                $this->passwordStrengthRule(),
            ],
            'agreed_to_terms' => ['accepted'],
        ];
    }

    protected function messages(): array
    {
        return [
            'agreed_to_terms.accepted' => 'Please accept our policies to create an account.',
        ];
    }

    public function register()
    {
        $this->validate();

        // Prepare the data for user creation
        $data = [
            'name' => $this->name,
            'email' => $this->email,
            'password' => $this->password,
            'timezone' => $this->timezone,
        ];

        $user = $this->createUser($data);

        // If user creation failed, respond with an error message
        $this->respondError(
            message: 'Failed to create user. Please try again.',
            if: ! $user
        );

        // Redirect to email verification page if email verification is required and strict
        if (kSiteConfig('email-settings.verification-strict', default: false)) {
            return to_route('email.verification', ['user' => $user->email])
                ->with([
                    'message' => 'Please verify your email address to access this page.',
                ]);
        }

        // Redirect the user to their respective dashboard based on their role
        return $this->userDashboardRedirect();
    }
};
?>

<x-slot:tag>Get started</x-slot:tag>
<x-slot:title>Create your account</x-slot:title>
<x-slot:description>It takes less than a minute.</x-slot:description>
<x-slot:extra>
    <flux:text class="mt-8 text-center dark:text-slate-400">
        Already have an account?
        <flux:link href="{{ route('login') }}" variant="ghost">
            Sign in
        </flux:link>
    </flux:text>
</x-slot:extra>

<form wire:submit.throttle.500ms="register" class="mt-8 space-y-5">
    <flux:input wire:model="name" autocomplete="name" autofocus placeholder="Enter your full name" />
    <flux:error name="name" />
    <flux:input wire:model="email" type="email" autocomplete="email" placeholder="Enter your email address: example@mail.com" />
    <flux:error name="email" />

    <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-2">
        <flux:field>
            <flux:label>Password</flux:label>
            <x-form.password wire:model="password" :label="null" />
        </flux:field>
        <x-form.password label="Confirm password" wire:model="password_confirmation" />
    </div>
    <flux:error name="password" />
    <flux:text class="text-xs">{!! $passwordNote !!}</flux:text>

    <x-form.consent-field />

    <flux:button type="submit" variant="primary" class="w-full">
        Create account
    </flux:button>

    <flux:button href="{{ route('passwordless') }}" icon="envelope" class="w-full">
        Sign up without a password
    </flux:button>

    @script
        <script>
            // The browser is the only thing that knows the visitor's zone. Report it
            // once so timestamps render locally before the account has a saved one.
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
</form>
