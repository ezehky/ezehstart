<?php

namespace App\Services;

use App\Mail\PasswordlessOtpEmail;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

/**
 * Sign-in codes for the passwordless flow. Codes are keyed by email address rather
 * than user id, because registration issues a code before the account exists.
 */
#[Singleton]
class PasswordlessOtpService
{
    public const EXPIRATION_MINUTES = 10;

    /**
     * Wrong codes tolerated before the code is destroyed. A six-digit code only
     * stays guessable while an attacker can keep trying it.
     */
    public const MAX_ATTEMPTS = 5;

    /**
     * Seconds a requester must wait before another code is issued for the address.
     */
    public const RESEND_THROTTLE_SECONDS = 60;

    /**
     * Issue a fresh code for this address and mail it. Only the hash is stored, so a
     * leaked cache cannot be replayed.
     */
    public function send(string $email, string $name, bool $isNewAccount): void
    {
        $otp = (string) random_int(100000, 999999);

        Cache::put(
            $this->codeKey($email),
            Hash::make($otp),
            now()->addMinutes(self::EXPIRATION_MINUTES),
        );

        // A new code starts with a clean allowance.
        Cache::forget($this->attemptsKey($email));

        Cache::put(
            $this->throttleKey($email),
            now()->addSeconds(self::RESEND_THROTTLE_SECONDS)->timestamp,
            now()->addSeconds(self::RESEND_THROTTLE_SECONDS),
        );

        Mail::to($email)->sendNow(new PasswordlessOtpEmail(
            $name,
            $email,
            $otp,
            self::EXPIRATION_MINUTES,
            $isNewAccount,
        ));
    }

    public function verify(string $email, string $otp): bool
    {
        $cachedOtp = Cache::get($this->codeKey($email));

        if (! \is_string($cachedOtp) || ! Hash::check($otp, $cachedOtp)) {
            $this->registerFailedAttempt($email);

            return false;
        }

        $this->forget($email);

        return true;
    }

    /**
     * Seconds left before another code may be requested for this address, 0 when the
     * requester is free to ask again.
     */
    public function secondsUntilResend(string $email): int
    {
        $availableAt = Cache::get($this->throttleKey($email));

        return \is_numeric($availableAt) ? max(0, (int) $availableAt - now()->timestamp) : 0;
    }

    public function forget(string $email): void
    {
        Cache::forget($this->codeKey($email));
        Cache::forget($this->attemptsKey($email));
    }

    /**
     * Count the miss and burn the code once the allowance is spent, so the code
     * cannot be brute forced inside its expiry window.
     */
    private function registerFailedAttempt(string $email): void
    {
        $attempts = (int) Cache::get($this->attemptsKey($email)) + 1;

        Cache::put(
            $this->attemptsKey($email),
            $attempts,
            now()->addMinutes(self::EXPIRATION_MINUTES),
        );

        if ($attempts >= self::MAX_ATTEMPTS) {
            Cache::forget($this->codeKey($email));
        }
    }

    private function codeKey(string $email): string
    {
        return 'passwordless-otp:'.$this->emailHash($email);
    }

    private function attemptsKey(string $email): string
    {
        return 'passwordless-otp-attempts:'.$this->emailHash($email);
    }

    private function throttleKey(string $email): string
    {
        return 'passwordless-otp-throttle:'.$this->emailHash($email);
    }

    /**
     * Addresses are hashed so they are not readable in the cache store, and so the
     * key stays valid whatever characters the address contains.
     */
    private function emailHash(string $email): string
    {
        return sha1(mb_strtolower(trim($email)));
    }
}
