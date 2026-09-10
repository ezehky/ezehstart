<?php

namespace App\Traits;

use App\Enums\ActivityActionEnum;
use App\Enums\UserRoleEnum;
use App\Mail\LoginEmail;
use App\Models\Policy;
use App\Models\Role;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\EmailVerificationOtpService;
use App\Services\PolicyContentService;
use App\Services\TwoFactorService;
use App\Services\UserService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

trait WithAuthWorker
{
    use WithFormResponseMessage;

    protected function userDashboardRedirect(array $with = [])
    {
        $user = auth()->user();

        // Admin
        if ($user->isAdmin()) {
            return redirect()->intended(route('admin.dashboard'))->with($with);
        }

        // Everyone else
        return redirect()->intended(route('user.dashboard'))->with($with);
    }

    protected function logActivity(ActivityActionEnum $action, string $description = ''): void
    {
        app(ActivityLogService::class)->logActivity($action, $description);
    }

    /**
     * Register an account and everything that has to exist alongside it.
     *
     * The user, their role and their profile are written together, so a failure
     * halfway through cannot leave an account that can sign in but reach nothing.
     * Mail is sent after the commit, never inside it.
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
                // Create the user
                $user = User::query()->create([
                    ...$data,
                    'ip_address' => request()->ip(),
                ]);

                // Assign the default role to the user
                $this->assignDefaultRole($user);

                // Create the user profile if provided
                $user->userProfile()->create([...$profileData, 'settings' => $userService->profileDefaultSettings()]);

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
            Log::channel('code')->error('Error creating user: '.$e->getMessage(), [
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

    /**
     * The role every self-registered account starts with.
     */
    private function assignDefaultRole(User $user): void
    {
        $role = Role::query()->firstOrCreate(['name' => UserRoleEnum::USER]);

        $user->roles()->syncWithoutDetaching([$role->id]);
    }
}
