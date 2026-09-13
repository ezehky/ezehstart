<?php

namespace App\Services;

use App\Mail\EmailVerificationOtpEmail;
use App\Mail\WelcomeEmail;
use App\Models\User;
use App\Traits\WithOtpGuard;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

#[Singleton]
class EmailVerificationOtpService
{
    use WithOtpGuard;

    public const EXPIRATION_MINUTES = 15;

    public function sendWelcomeEmail(User $user, bool $sendOtp = true): void
    {
        $otp = $sendOtp ? $this->cacheOtp($user) : null;

        Mail::to($user->email)->sendNow(new WelcomeEmail($user, $otp, self::EXPIRATION_MINUTES));
    }

    public function sendResentOtpEmail(User $user): void
    {
        $otp = $this->cacheOtp($user);

        Mail::to($user->email)->sendNow(new EmailVerificationOtpEmail($user, $otp, self::EXPIRATION_MINUTES));
    }

    public function verify(User $user, string $otp): bool
    {
        // Check if the OTP is valid and matches the cached value
        $cachedOtp = Cache::get($this->cacheKey($user));

        // If the cached OTP is not a string or does not match the provided OTP, return false
        if (! \is_string($cachedOtp) || ! Hash::check($otp, $cachedOtp)) {
            // A spent allowance destroys the code rather than merely refusing this one
            // guess, so the million-wide space cannot be walked inside the expiry.
            if ($this->registerFailedAttempt($this->scope($user))) {
                $this->forget($user);
            }

            return false;
        }

        // Mark the user's email as verified and clear the cached OTP
        $user->markEmailAsVerified();

        // Clear the cached OTP after successful verification
        $this->forget($user);

        // Return true to indicate successful verification
        return true;
    }

    /**
     * Seconds left before another code may be requested for this account.
     */
    public function secondsUntilResendFor(User $user): int
    {
        return $this->secondsUntilResend($this->scope($user));
    }

    public function forget(User $user): void
    {
        Cache::forget($this->cacheKey($user));

        $this->forgetOtpAttempts($this->scope($user));
    }

    protected function otpLifetimeMinutes(): int
    {
        return self::EXPIRATION_MINUTES;
    }

    private function cacheOtp(User $user): string
    {
        $otp = (string) random_int(100000, 999999);

        Cache::put(
            $this->cacheKey($user),
            Hash::make($otp),
            now()->addMinutes(self::EXPIRATION_MINUTES),
        );

        $this->startOtpWindow($this->scope($user));

        return $otp;
    }

    private function cacheKey(User $user): string
    {
        return 'email-verification-otp:'.$user->getKey();
    }

    private function scope(User $user): string
    {
        return 'email-verification:'.$user->getKey();
    }
}
