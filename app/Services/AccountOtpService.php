<?php

namespace App\Services;

use App\Mail\AccountOtpEmail;
use App\Models\User;
use App\Traits\WithOtpGuard;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

#[Singleton]
class AccountOtpService
{
    use WithOtpGuard;

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

        $this->startOtpWindow($this->scope($user, $purpose));

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
            // Spending the allowance destroys the code. These three purposes each end
            // in an irreversible change to the account, so the code guarding one is
            // not something to leave standing while it is guessed at.
            if ($this->registerFailedAttempt($this->scope($user, $purpose))) {
                $this->forget($user, $purpose);
            }

            return false;
        }

        $this->forget($user, $purpose);

        return true;
    }

    /**
     * Seconds left before another code may be requested for this purpose.
     */
    public function secondsUntilResendFor(User $user, string $purpose): int
    {
        return $this->secondsUntilResend($this->scope($user, $purpose));
    }

    public function forget(User $user, string $purpose): void
    {
        Cache::forget($this->cacheKey($user, $purpose));

        $this->forgetOtpAttempts($this->scope($user, $purpose));
    }

    protected function otpLifetimeMinutes(): int
    {
        return self::EXPIRATION_MINUTES;
    }

    private function cacheKey(User $user, string $purpose): string
    {
        return "account-otp:{$purpose}:{$user->getKey()}";
    }

    /**
     * One outstanding code per purpose per account, so a code being guessed at for a
     * password change does not spend the allowance for an email change.
     */
    private function scope(User $user, string $purpose): string
    {
        return "account:{$purpose}:{$user->getKey()}";
    }
}
