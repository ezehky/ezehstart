<?php

namespace App\Services;

use App\Enums\ActivityActionEnum;
use App\Mail\PasswordResetOtpEmail;
use App\Models\User;
use App\Traits\WithOtpGuard;
use Carbon\Carbon;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

/**
 * Reset codes for the forgotten-password flow.
 *
 * The code lives in Laravel's own `password_reset_tokens` table rather than the cache,
 * so the broker's `expire` setting stays the one place the lifetime is configured and a
 * pending reset survives a cache flush. Only the hash is stored.
 *
 * The guess allowance and the resend floor come from WithOtpGuard, the same pair the
 * other three code flows use — this one sits in front of a password write, so it is the
 * flow that can least afford to be the one without them.
 */
#[Singleton]
class PasswordResetOtpService
{
    use WithOtpGuard;

    /**
     * The forgot-password screen's entry point: send a code if the address belongs
     * to an account, and do nothing a visitor can see if it does not.
     *
     * The resend floor starts for every address, known or not. Starting it only for
     * real accounts would turn "please wait" into the very answer the screen no
     * longer gives — type an address twice and see whether it was held back.
     * A newsletter row is not an account, so it is treated as unknown too.
     */
    public function sendTo(string $email): void
    {
        $user = User::query()->registered()->where('email', $email)->first();

        if (! $user) {
            $this->startOtpWindow($this->scope($email));

            return;
        }

        $this->send($user);
    }

    /**
     * Issue a fresh code for this account and mail it, replacing any code already
     * outstanding for the address.
     */
    public function send(User $user): void
    {
        $otp = (string) random_int(100000, 999999);

        DB::table($this->table())->updateOrInsert(
            ['email' => $user->email],
            ['token' => Hash::make($otp), 'created_at' => now()],
        );

        $this->startOtpWindow($this->scope($user->email));

        Mail::to($user->email)->sendNow(new PasswordResetOtpEmail($user, $otp));

        app(ActivityLogService::class)->logActivity(
            ActivityActionEnum::PASSWORD_RESET_REQUEST,
            $user->email,
        );
    }

    /**
     * Is this the outstanding code for the address, and is it still inside its window?
     */
    public function verify(string $email, string $otp): bool
    {
        $reset = DB::table($this->table())->where('email', $email)->first();

        $expiredAt = now()->subMinutes($this->expirationMinutes());

        $valid = $reset
            && Carbon::parse($reset->created_at)->greaterThanOrEqualTo($expiredAt)
            && Hash::check($otp, $reset->token);

        if (! $valid) {
            // A spent allowance deletes the row. Without this the code is a six-digit
            // secret standing for an hour in front of a password write, which is the
            // whole space given an unlimited guess rate.
            if ($this->registerFailedAttempt($this->scope($email))) {
                $this->forget($email);
            }

            return false;
        }

        return true;
    }

    /**
     * Seconds left before another code may be requested for this address.
     */
    public function secondsUntilResendFor(string $email): int
    {
        return $this->secondsUntilResend($this->scope($email));
    }

    /**
     * Drop the outstanding code, so an abandoned or spent one cannot be used later.
     */
    public function forget(string $email): void
    {
        DB::table($this->table())->where('email', $email)->delete();

        $this->forgetOtpAttempts($this->scope($email));
    }

    /**
     * The broker's own setting, so the lifetime is configured in one place.
     */
    public function expirationMinutes(): int
    {
        return (int) config('auth.passwords.users.expire', 60);
    }

    protected function otpLifetimeMinutes(): int
    {
        return $this->expirationMinutes();
    }

    private function table(): string
    {
        return (string) config('auth.passwords.users.table', 'password_reset_tokens');
    }

    private function scope(string $email): string
    {
        return 'password-reset:'.sha1(mb_strtolower(trim($email)));
    }
}
