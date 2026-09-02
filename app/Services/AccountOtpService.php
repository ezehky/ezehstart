<?php

namespace App\Services;

use App\Mail\AccountOtpEmail;
use App\Models\User;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

#[Singleton]
class AccountOtpService
{
    public const EXPIRATION_MINUTES = 10;

    /**
     * Purpose => human label shown in the OTP email.
     */
    private const PURPOSES = [
        'change-password' => 'confirm your password change',
        'change-email' => 'confirm your email change',
        'delete-account' => 'confirm your account deletion',
    ];

    public function send(User $user, string $purpose): string
    {
        $otp = (string) random_int(100000, 999999);

        Cache::put(
            $this->cacheKey($user, $purpose),
            Hash::make($otp),
            now()->addMinutes(self::EXPIRATION_MINUTES),
        );

        Mail::to($user->email)->sendNow(new AccountOtpEmail(
            $user,
            $otp,
            self::PURPOSES[$purpose] ?? 'confirm this change',
            self::EXPIRATION_MINUTES,
        ));

        return $otp;
    }

    public function verify(User $user, string $purpose, string $otp): bool
    {
        $cachedOtp = Cache::get($this->cacheKey($user, $purpose));

        if (! \is_string($cachedOtp) || ! Hash::check($otp, $cachedOtp)) {
            return false;
        }

        Cache::forget($this->cacheKey($user, $purpose));

        return true;
    }

    private function cacheKey(User $user, string $purpose): string
    {
        return "account-otp:{$purpose}:{$user->getKey()}";
    }
}
