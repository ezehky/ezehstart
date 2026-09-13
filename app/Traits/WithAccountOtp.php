<?php

namespace App\Traits;

use App\Models\User;
use App\Services\AccountOtpService;
use App\Services\EmailVerificationOtpService;

/**
 * Asking one of the account services for a code, with the resend floor checked first.
 *
 * The floor lives here rather than at each call site because there are five of them
 * across three screens, and a guard that has to be remembered five times is a guard
 * with four places to forget it. Refusing early also means the page says how long is
 * left rather than silently sending anyway.
 */
trait WithAccountOtp
{
    use WithFormResponseMessage;

    /**
     * Send a confirmation code for one of the account purposes — a password change, an
     * email change, an account deletion.
     */
    protected function sendAccountOtp(User $user, string $purpose, string $field): void
    {
        $service = app(AccountOtpService::class);

        $this->guardOtpResend($service->secondsUntilResendFor($user, $purpose), $field);

        $service->send($user, $purpose);
    }

    /**
     * Send another email-verification code. A separate service, but the same floor —
     * this is the one an unverified visitor can reach, so it is the one most worth
     * holding down.
     */
    protected function sendVerificationOtp(User $user, string $field): void
    {
        $service = app(EmailVerificationOtpService::class);

        $this->guardOtpResend($service->secondsUntilResendFor($user), $field);

        $service->sendResentOtpEmail($user);
    }

    private function guardOtpResend(int $secondsRemaining, string $field): void
    {
        $this->respondError(
            "Please wait {$secondsRemaining} seconds before requesting another code.",
            $secondsRemaining > 0,
            field: $field,
        );
    }
}
