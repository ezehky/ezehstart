<?php

namespace App\Enums;

use App\Traits\WithEnumHelpers;

/**
 * The mail the app sends on its own, which a builder template can stand in for.
 *
 * Each case is a slot. A template assigned to it (email_templates.system_email)
 * replaces the Blade view that mailable ships with; with nothing assigned, the
 * view is sent exactly as before. So a slot can never be left broken — only
 * redesigned.
 *
 * Adding one is a case here, then WithSystemTemplate on the mailable with
 * systemEmail() and systemContext() filled in. The tokens listed below are the
 * ones systemContext() provides, and the builder shows them for the slot.
 */
enum SystemEmailEnum: string
{
    use WithEnumHelpers;

    case LOGIN = 'login';
    case WELCOME = 'welcome';
    case UNVERIFIED_WARNING = 'unverified-warning';
    case INACTIVITY_REMINDER = 'inactivity-reminder';

    public function label(bool $lowercase = false): string
    {
        $label = match ($this) {
            self::LOGIN => 'Sign-in alert',
            self::WELCOME => 'Welcome (sign-up)',
            self::UNVERIFIED_WARNING => 'Unverified account warning',
            self::INACTIVITY_REMINDER => 'We missed you',
        };

        return $lowercase ? mb_strtolower($label) : $label;
    }

    public function description(): string
    {
        return match ($this) {
            self::LOGIN => 'Sent after every successful sign-in, so the account holder hears about one they did not make.',
            self::WELCOME => 'Sent once, when an account is created. Carries the verification code when email verification is on.',
            self::UNVERIFIED_WARNING => 'Sent once to an account that has not verified its email, before the sweep removes it.',
            self::INACTIVITY_REMINDER => 'Sent to a member who has not been seen for the configured number of days. Once per absence.',
        };
    }

    /**
     * The subject used when the template leaves its own blank.
     */
    public function defaultSubject(): string
    {
        return match ($this) {
            self::LOGIN => 'New sign-in to {{site.name}}',
            self::WELCOME => 'Welcome to {{site.name}}',
            self::UNVERIFIED_WARNING => 'Verify your email to keep your {{site.name}} account',
            self::INACTIVITY_REMINDER => 'We missed you at {{site.name}}',
        };
    }

    /**
     * The tokens this mail adds on top of the site and recipient ones every
     * template already has, [token => label].
     *
     * @return array<string, string>
     */
    public function tokens(): array
    {
        return match ($this) {
            self::LOGIN => [
                '{{login.ip}}' => 'IP address',
                '{{login.time}}' => 'Time of sign-in',
                '{{login.device}}' => 'Browser and device',
            ],
            self::WELCOME => [
                '{{welcome.code}}' => 'Verification code (empty when verification is off)',
                '{{welcome.expires_minutes}}' => 'Minutes the code is valid for',
                '{{welcome.dashboard_url}}' => 'Dashboard link',
            ],
            self::UNVERIFIED_WARNING => [
                '{{unverified.days_left}}' => 'Days before the account is removed',
                '{{unverified.verify_url}}' => 'Verification link',
            ],
            self::INACTIVITY_REMINDER => [
                '{{inactivity.days}}' => 'Days since the last visit',
                '{{inactivity.login_url}}' => 'Sign-in link',
            ],
        };
    }
}
