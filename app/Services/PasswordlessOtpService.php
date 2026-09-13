<?php

namespace App\Services;

use App\Mail\PasswordlessOtpEmail;
use App\Traits\WithOtpGuard;
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
    use WithOtpGuard;

    public const EXPIRATION_MINUTES = 10;

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

        // A new code opens a fresh resend window and starts with a clean allowance.
        $this->startOtpWindow($this->scope($email));

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
            // Spending the allowance burns the code, so it cannot be brute forced
            // inside its expiry window.
            if ($this->registerFailedAttempt($this->scope($email))) {
                $this->forget($email);
            }

            return false;
        }

        $this->forget($email);

        return true;
    }

    /**
     * Seconds left before another code may be requested for this address, 0 when the
     * requester is free to ask again.
     */
    public function secondsUntilResendFor(string $email): int
    {
        return $this->secondsUntilResend($this->scope($email));
    }

    public function forget(string $email): void
    {
        Cache::forget($this->codeKey($email));

        $this->forgetOtpAttempts($this->scope($email));
    }

    protected function otpLifetimeMinutes(): int
    {
        return self::EXPIRATION_MINUTES;
    }

    private function codeKey(string $email): string
    {
        return 'passwordless-otp:'.$this->emailHash($email);
    }

    private function scope(string $email): string
    {
        return 'passwordless:'.$this->emailHash($email);
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
