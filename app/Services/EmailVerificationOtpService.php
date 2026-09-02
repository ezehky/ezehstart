<?php

namespace App\Services;

use App\Mail\EmailVerificationOtpEmail;
use App\Mail\WelcomeEmail;
use App\Models\User;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

#[Singleton]
class EmailVerificationOtpService
{
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
            return false;
        }

        // Mark the user's email as verified and clear the cached OTP
        $user->markEmailAsVerified();

        // Clear the cached OTP after successful verification
        Cache::forget($this->cacheKey($user));

        // Return true to indicate successful verification
        return true;
    }

    private function cacheOtp(User $user): string
    {
        $otp = (string) random_int(100000, 999999);

        Cache::put(
            $this->cacheKey($user),
            Hash::make($otp),
            now()->addMinutes(self::EXPIRATION_MINUTES),
        );

        return $otp;
    }

    private function cacheKey(User $user): string
    {
        return 'email-verification-otp:'.$user->getKey();
    }
}
