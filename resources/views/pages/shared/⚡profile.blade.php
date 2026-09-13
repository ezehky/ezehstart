<?php

use App\Enums\ActivityActionEnum;
use App\Enums\SocialHandleEnum;
use App\Models\User;
use App\Services\AccountOtpService;
use App\Services\ActivityLogService;
use App\Services\UserService;
use App\Traits\WithFormResponseMessage;
use App\Traits\WithPasswordTools;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    use WithFormResponseMessage, WithPasswordTools;

    public User $user;

    /**
     * The public-facing blurb that runs under this author's byline.
     */
    public ?string $bio = null;

    /**
     * Handles keyed by SocialHandleEnum value, stored exactly as typed — "@someone",
     * "someone" or a full URL all work, and the address is rebuilt on render.
     *
     * @var array<string, string>
     */
    public array $socials = [];

    public int $passwordStep = 1; // 1 = current password, 2 = otp, 3 = new password

    public string $current_password = '';

    public string $password_otp = '';

    public string $new_password = '';

    public string $new_password_confirmation = '';

    public function mount(): void
    {
        $this->user = auth()->user();

        $this->loadAuthorProfile();

        kSetSiteTitle('profile');
    }

    /**
     * Does this account get the author card?
     *
     * Only somebody who actually writes. A bio and a set of handles on an account
     * whose name never appears under a post is a form nobody will ever see the output
     * of — see User::isAuthor().
     */
    #[Computed]
    public function isAuthor(): bool
    {
        return $this->user->isAuthor();
    }

    /**
     * The platforms the author card offers, in one place so the form and the rules
     * cannot drift apart.
     *
     * @return array<int, SocialHandleEnum>
     */
    #[Computed]
    public function socialPlatforms(): array
    {
        return SocialHandleEnum::profiles();
    }

    public function saveAuthorProfile(): bool
    {
        // The card is only rendered for an author, which stops nobody who can open a
        // console and post at this component directly.
        $this->respondError(
            'Only accounts on the author role have a public profile to write.',
            if: ! $this->isAuthor,
        );

        $this->validate([
            'bio' => ['nullable', 'string', 'max:1000'],
            'socials' => ['array'],
            'socials.*' => ['nullable', 'string', 'max:255'],
        ]);

        $changed = app(UserService::class)->updateAuthorProfile($this->user, $this->bio, $this->socials);

        $this->respondPrimary(if: ! $changed);

        $this->user->load('userProfile');
        $this->loadAuthorProfile();

        return $this->respondSuccess('Your author profile has been saved.');
    }

    /**
     * Fill the form from the stored profile. A platform nobody has filled in comes
     * back as an empty box rather than a missing one.
     */
    private function loadAuthorProfile(): void
    {
        $profile = $this->user->userProfile;
        $stored = $profile?->socialsArray() ?? [];

        $this->bio = $profile?->bio;

        foreach (SocialHandleEnum::profiles() as $platform) {
            $this->socials[$platform->value] = (string) ($stored[$platform->value] ?? '');
        }
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
    {{-- The account tabs, but only in the member workspace: every route the nav
         points at is a user.* one, and the admin workspace has a profile page
         and nothing else to tab between. --}}
    @if ($user->isUser())
        <x-dashboard.tab-nav
            active="profile"
            isUser
            title="My profile"
            subtitle="Your account details. Contact an administrator to update any of this information."
        />
    @else
        <div>
            <flux:heading level="1" size="xl">My profile</flux:heading>
            <flux:text class="mt-1">Your account details. Contact an administrator to update any of this information.</flux:text>
        </div>
    @endif

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
                    <flux:badge size="sm" :color="$user->user_type->isAdmin() ? 'lime' : 'sky'">
                        {{ $user->user_type->label() }}
                    </flux:badge>
                    @foreach ($user->roles as $role)
                        <flux:badge size="sm" color="zinc">{{ $role->name }}</flux:badge>
                    @endforeach
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

    {{-- Author profile. Only for accounts that actually write: this is the copy that
         runs under a byline, so an account whose name never appears under a post has
         nothing to fill it in for. --}}
    @if ($this->isAuthor)
        <flux:card class="space-y-6">
            <div>
                <flux:heading level="2" size="lg">Author profile</flux:heading>
                <flux:text class="mt-1">
                    What readers see under your byline. Everything here is public.
                </flux:text>
            </div>

            <form wire:submit="saveAuthorProfile" class="space-y-6">
                <flux:textarea
                    wire:model="bio"
                    label="Bio"
                    rows="3"
                    placeholder="A couple of sentences about who you are and what you write about."
                />

                <div class="grid gap-4 sm:grid-cols-2">
                    @foreach ($this->socialPlatforms as $platform)
                        <flux:input
                            wire:key="social-{{ $platform->value }}"
                            wire:model="socials.{{ $platform->value }}"
                            :label="$platform->label()"
                            :icon="$platform->icon()"
                            placeholder="Handle or full link"
                        />
                    @endforeach
                </div>

                <flux:text class="text-xs">
                    A handle or a full link both work — leave a box empty and that platform is
                    simply not shown.
                </flux:text>

                <flux:button type="submit" variant="primary">Save author profile</flux:button>
            </form>
        </flux:card>
    @endif

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
                <x-form.password label="New password" wire:model="new_password" :note="$passwordNote" />
                <x-form.password label="Confirm new password" wire:model="new_password_confirmation" />
                <div class="flex gap-3">
                    <flux:button type="submit" variant="primary">Save new password</flux:button>
                    <flux:button type="button" variant="ghost" wire:click="passwordRestart">Cancel</flux:button>
                </div>
            </form>
        @endif
    </flux:card>
</div>
