<?php

namespace App\Traits;

use App\Enums\ActivityActionEnum;
use App\Enums\SocialProviderEnum;
use App\Enums\StatusUser;
use App\Mail\LoginEmail;
use App\Models\Policy;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\EmailVerificationOtpService;
use App\Services\PolicyContentService;
use App\Services\SocialAccountService;
use App\Services\TwoFactorService;
use App\Services\UserService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;
use Livewire\Attributes\Computed;

trait WithAuthWorker
{
    use WithFormResponseMessage;

    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // WHAT THIS INSTALL OFFERS

    /**
     * Whether the six-digit email sign-in is on offer.
     *
     * Every screen that links to it asks here rather than reading the flag itself,
     * so the switch cannot be honoured on one page and forgotten on the next — the
     * passwordless route aborts on the same answer.
     */
    #[Computed]
    public function passwordlessEnabled(): bool
    {
        return (bool) kSiteFlag('security', 'passwordless-login', true);
    }

    /**
     * Whether two-factor authentication is on offer.
     */
    #[Computed]
    public function twoFactorEnabled(): bool
    {
        return (bool) kSiteFlag('security', 'two-factor', false);
    }

    /**
     * The providers this install can actually sign somebody in with. Empty
     * whenever the master switch is off, the provider's own switch is off, or
     * nothing has credentials — a button that lands on a provider error page is
     * worse than no button.
     *
     * @return Collection<int, SocialProviderEnum>
     */
    #[Computed]
    public function socialProviders()
    {
        $service = app(SocialAccountService::class);

        return $service->isAvailable() ? $service->enabledProviders() : collect();
    }

    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // SIGN IN

    protected function userDashboardRedirect(array $with = [])
    {
        return redirect()
            ->intended(auth()->user()->user_type->dashboardRoute())
            ->with($with);
    }

    protected function logActivity(ActivityActionEnum $action, string $description = ''): void
    {
        app(ActivityLogService::class)->logActivity($action, $description);
    }

    /**
     * The email rule every sign-up form uses.
     *
     * A plain unique() would refuse an address that is on the newsletter list,
     * because that address already has a users row — see
     * StatusUser::NEWSLETTER_SUBSCRIBER. Those rows are not accounts and must not
     * stand in the way of somebody opening one; createUser() claims the row
     * instead of writing a second.
     */
    protected function emailAvailableRule(): Unique
    {
        return Rule::unique('users', 'email')
            ->where(fn ($query) => $query->where('status', '!=', StatusUser::NEWSLETTER_SUBSCRIBER->value));
    }

    /**
     * Register an account and everything that has to exist alongside it.
     *
     * The user, their profile and their consent records are written together, so a
     * failure halfway through cannot leave an account that exists without the record
     * of what it agreed to. Mail is sent after the commit, never inside it.
     *
     * An address already on the newsletter list is claimed rather than duplicated:
     * the row keeps its id and its notification preferences, so somebody who signed
     * up for the newsletter in March and registered in June is one row that is still
     * subscribed, not two rows the unique index would have refused anyway.
     *
     * Self-registration only ever makes a member. users carry no role — the type
     * column defaults to UserTypeEnum::USER and there is nothing else to assign.
     */
    private function createUser(array $data, array $profileData = [], bool $sendOtp = true): ?User
    {
        $userService = app(UserService::class);

        // Resolved before the transaction opens. Which policies are in force is a
        // read against the same rows the consent checkbox was rendered from, and
        // doing it inside the write would hold the account open on a query that
        // has nothing to do with creating it.
        $consentPolicies = app(PolicyContentService::class)->getCurrentRequiringConsent();

        try {
            $user = DB::transaction(function () use ($data, $profileData, $userService, $consentPolicies) {
                // Create the user, or take over the newsletter row that already
                // holds this address. Status is set explicitly on the claim: the
                // column default only applies to an insert, and the row being
                // claimed is carrying NEWSLETTER_SUBSCRIBER.
                $claimable = User::query()
                    ->where('email', $data['email'] ?? '')
                    ->where('status', StatusUser::NEWSLETTER_SUBSCRIBER)
                    ->first();

                $user = $claimable ?: new User;

                $user->fill([
                    ...$data,
                    'status' => StatusUser::ACTIVE,
                    'ip_address' => request()->ip(),
                ])->save();

                // Create the user profile if provided. updateOrCreate rather than
                // create because a claimed row is not guaranteed to be profileless
                // forever — the relation is one-to-one, and a second row here would
                // be a duplicate nothing else in the app expects.
                $user->userProfile()->updateOrCreate(
                    [],
                    [...$profileData, 'settings' => $userService->profileDefaultSettings()],
                );

                // The account and its consent records are written together. An
                // account that exists without the record of what it agreed to is
                // exactly the state this feature is here to prevent.
                $consentPolicies->each(fn (Policy $policy) => $userService->recordConsent($policy, $user));

                return $user;
            });

            // Send welcome email after the transaction is committed
            app(EmailVerificationOtpService::class)->sendWelcomeEmail($user, $sendOtp);

            // Log Activity: Log the registration activity
            $this->logActivity(ActivityActionEnum::REGISTER);

            // Log the user in after registration
            Auth::login($user, true);
            session()->regenerate();

            return $user;
        } catch (\Throwable $e) {
            // Log the error for debugging purposes
            Log::channel('ezeh')->error('Error creating user: '.$e->getMessage(), [
                'exception' => $e,
                'data' => $data,
                'profileData' => $profileData,
            ]);
        }

        return null;
    }

    /**
     * @param  string|null  $description  How the session was obtained. Defaults to the
     *                                    action's own wording when omitted.
     */
    protected function loginUser(?string $description = null)
    {
        $user = auth()->user();

        // Log Activity: Log the login activity
        app(ActivityLogService::class)->logActivity(ActivityActionEnum::LOGIN, $description);

        // Regenerate the session to prevent session fixation attacks. Resolved from the
        // container rather than the request, matching createUser() above.
        session()->regenerate();

        Mail::to($user->email)->queue(new LoginEmail($user, request()->ip()));
    }

    // ||||||||||||||||||||||||||||||||||||||||||||||||
    // TWO-FACTOR

    /**
     * The session key holding the account that has passed its first factor but
     * not yet its second.
     */
    public const TWO_FACTOR_SESSION_KEY = 'two-factor.user-id';

    public const TWO_FACTOR_REMEMBER_KEY = 'two-factor.remember';

    /**
     * Stand a signed-in user back down and send them to the code prompt, unless
     * this browser was remembered.
     *
     * Returns a redirect when the second factor is owed, or null when sign-in may
     * simply continue. The session is dropped in between on purpose: an account
     * that is half-way through sign-in must not be able to reach anything, and
     * leaving it authenticated "just until the next page" is how that happens.
     */
    protected function twoFactorChallengeRedirect(User $user, bool $remember = false)
    {
        $service = app(TwoFactorService::class);

        if (! $service->isEnabledFor($user)) {
            return null;
        }

        // A remembered browser skips the prompt for the configured window.
        $cookie = request()->cookie($service->rememberCookieName());

        if (\is_string($cookie) && $user->twoFactor?->remembersDevice($cookie)) {
            return null;
        }

        Auth::logout();

        session()->put(self::TWO_FACTOR_SESSION_KEY, $user->id);
        session()->put(self::TWO_FACTOR_REMEMBER_KEY, $remember);

        return redirect()->route('two-factor.challenge');
    }

    // ||||||||||||||||||||||||||||||||||||||||||||||||
    // THROTTLING

    /**
     * The bucket a sign-in attempt counts against.
     *
     * Keyed on the email *and* the IP together. On the email alone, anybody could
     * lock a known account out by failing against it on purpose; on the IP alone,
     * one office behind a single address would lock each other out.
     */
    protected function throttleKey(string $identifier, string $prefix = 'login'): string
    {
        return $prefix.'|'.mb_strtolower(trim($identifier)).'|'.request()->ip();
    }

    /**
     * Stop here if this key has run out of attempts, telling the caller how long
     * they have to wait. Call it before checking credentials, never after — the
     * point is to make guessing expensive, and a check that only runs on success
     * costs an attacker nothing.
     */
    protected function ensureIsNotRateLimited(string $identifier, string $field = 'email', string $prefix = 'login'): void
    {
        $key = $this->throttleKey($identifier, $prefix);

        if (! RateLimiter::tooManyAttempts($key, $this->maxLoginAttempts())) {
            return;
        }

        $seconds = RateLimiter::availableIn($key);

        $this->respondError(
            'Too many attempts. Please try again in '.ceil($seconds / 60).' minute(s).',
            true,
            field: $field
        );
    }

    /**
     * Count a failed attempt. The window is how long the count survives, so a
     * decay of one minute with five attempts means five tries a minute.
     */
    protected function recordFailedAttempt(string $identifier, string $prefix = 'login'): void
    {
        RateLimiter::hit($this->throttleKey($identifier, $prefix), $this->loginDecaySeconds());
    }

    /**
     * Clear the count. A successful sign-in must reset it, or somebody who
     * mistyped their password four times would still be throttled afterwards.
     */
    protected function clearRateLimit(string $identifier, string $prefix = 'login'): void
    {
        RateLimiter::clear($this->throttleKey($identifier, $prefix));
    }

    /**
     * Clamped rather than trusted: these come from a JSON file an administrator
     * edits, and a zero would disable the throttle while the setting still looked
     * like a limit.
     */
    protected function maxLoginAttempts(): int
    {
        $max = (int) kSiteFlag('security', 'login-max-attempts', 5);

        return max(3, min($max ?: 5, 20));
    }

    protected function loginDecaySeconds(): int
    {
        $minutes = (int) kSiteFlag('security', 'login-decay-minutes', 1);

        return max(1, min($minutes ?: 1, 60)) * 60;
    }

    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // THE CAPTCHA CHALLENGE

    /**
     * A counter separate from the sign-in throttle, so the login screen can decide
     * whether to show the captcha *before* an email has been typed.
     *
     * The sign-in throttle is keyed on the email and the IP together, which is
     * right for locking one account out and useless for this — at first render
     * there is no email yet, and a visitor who refreshed the page would be handed a
     * clean slate. This counts failures by browser address alone: the question is
     * whether whoever is at this address has been failing sign-ins, not which
     * account they were aiming at.
     */
    protected function captchaThrottleKey(): string
    {
        return 'captcha|'.request()->ip();
    }

    /**
     * How many failures an address is allowed before it has to prove it is a
     * person. Two rather than the sign-in limit: the throttle exists to make
     * guessing slow and the captcha to make it manual, and there is no reason to
     * wait until the lockout is nearly spent to start asking.
     */
    protected function captchaAfterAttempts(): int
    {
        return 2;
    }

    /**
     * Count a failed sign-in against the address. The window is the throttle's own,
     * so the challenge and the lockout clear together.
     */
    protected function recordCaptchaFailure(): void
    {
        RateLimiter::hit($this->captchaThrottleKey(), $this->loginDecaySeconds());
    }

    /**
     * Clear the count. A sign-in that works is the evidence the challenge was
     * asking for.
     */
    protected function clearCaptchaFailures(): void
    {
        RateLimiter::clear($this->captchaThrottleKey());
    }

    /**
     * Has this address failed often enough to be asked?
     */
    protected function captchaChallenged(): bool
    {
        return RateLimiter::attempts($this->captchaThrottleKey()) >= $this->captchaAfterAttempts();
    }
}
