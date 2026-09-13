<?php

namespace App\Traits;

use Illuminate\Support\Facades\Cache;

/**
 * The two guards every emailed code needs: a ceiling on how many times one may be
 * guessed, and a floor on how often a new one may be asked for.
 *
 * A six-digit code is a million guesses wide, so an expiry is not on its own a bound —
 * given an unlimited guess rate the whole space fits inside the window. Destroying the
 * code once the allowance is spent is what closes that, and the resend floor is what
 * stops the destruction being undone by simply asking for another.
 *
 * The host service supplies a *scope*: whatever string identifies one outstanding code,
 * an address or an account and a purpose. This keeps the two counters beside it and
 * leaves the code itself — cache for most flows, a table row for the password reset —
 * to the service that owns it.
 */
trait WithOtpGuard
{
    /**
     * Wrong codes tolerated before the outstanding code is destroyed.
     */
    public const MAX_ATTEMPTS = 5;

    /**
     * Seconds a requester must wait before another code is issued for the scope.
     */
    public const RESEND_THROTTLE_SECONDS = 60;

    /**
     * How long a code issued by this service stays valid. Implemented rather than
     * read off a constant so the trait does not reach into the host for a name it
     * cannot guarantee is there.
     */
    abstract protected function otpLifetimeMinutes(): int;

    /**
     * Seconds left before another code may be requested for this scope, 0 when the
     * requester is free to ask again.
     */
    protected function secondsUntilResend(string $scope): int
    {
        $availableAt = Cache::get($this->otpThrottleKey($scope));

        return \is_numeric($availableAt) ? max(0, (int) $availableAt - now()->timestamp) : 0;
    }

    /**
     * Open the resend window and hand the new code a clean allowance. Called from
     * send(), so every issued code resets both counters together.
     */
    protected function startOtpWindow(string $scope): void
    {
        Cache::forget($this->otpAttemptsKey($scope));

        Cache::put(
            $this->otpThrottleKey($scope),
            now()->addSeconds(self::RESEND_THROTTLE_SECONDS)->timestamp,
            now()->addSeconds(self::RESEND_THROTTLE_SECONDS),
        );
    }

    /**
     * Count a miss.
     *
     * Returns true once the allowance is spent, which is the caller's signal to
     * destroy the code — the trait cannot do it, because only the service knows
     * whether the code is a cache entry or a row.
     */
    protected function registerFailedAttempt(string $scope): bool
    {
        $attempts = (int) Cache::get($this->otpAttemptsKey($scope)) + 1;

        Cache::put(
            $this->otpAttemptsKey($scope),
            $attempts,
            now()->addMinutes($this->otpLifetimeMinutes()),
        );

        return $attempts >= self::MAX_ATTEMPTS;
    }

    /**
     * Drop the allowance. Paired with whatever the service does to the code itself.
     */
    protected function forgetOtpAttempts(string $scope): void
    {
        Cache::forget($this->otpAttemptsKey($scope));
    }

    private function otpAttemptsKey(string $scope): string
    {
        return 'otp-attempts:'.$scope;
    }

    private function otpThrottleKey(string $scope): string
    {
        return 'otp-throttle:'.$scope;
    }
}
