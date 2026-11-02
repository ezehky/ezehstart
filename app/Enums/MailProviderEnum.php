<?php

namespace App\Enums;

use App\Traits\WithEnumHelpers;

/**
 * Where outgoing email goes, picked on Configuration › Email delivery.
 *
 * The keys live in .env (config/services.php and config/mail.php), never in the
 * site configuration, which an admin can read back through the JSON editor. A
 * provider without its keys reads as not ready and cannot be picked; until one
 * is, mail goes to the log — nothing is lost and nothing leaves the server.
 *
 * Resend and Brevo are called over plain HTTP (Services/Mail/), the way the
 * payment gateways and the AI providers are — no SDK. SMTP covers every other
 * service that offers it (Mailgun, SendGrid, Zoho, a host's own server). Adding
 * an API provider is a transport class and a case here.
 */
enum MailProviderEnum: string
{
    use WithEnumHelpers;

    case LOG = 'log';
    case RESEND = 'resend';
    case BREVO = 'brevo';
    case SMTP = 'smtp';

    public function label(bool $lowercase = false): string
    {
        $label = match ($this) {
            self::LOG => 'Log only',
            self::RESEND => 'Resend',
            self::BREVO => 'Brevo',
            self::SMTP => 'SMTP',
        };

        return $lowercase ? mb_strtolower($label) : $label;
    }

    public function description(): string
    {
        return match ($this) {
            self::LOG => 'Writes every email to the application log. Nothing is delivered — for development, or until a provider is set up.',
            self::RESEND => 'Sends through the Resend API. Needs a verified sending domain in Resend.',
            self::BREVO => 'Sends through the Brevo (formerly Sendinblue) transactional API. Needs a verified sender in Brevo.',
            self::SMTP => 'Any provider that offers SMTP — Mailgun, SendGrid, Zoho, Amazon SES, or your host\'s own mail server.',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::LOG => 'document-text',
            self::RESEND => 'paper-airplane',
            self::BREVO => 'envelope-open',
            self::SMTP => 'server-stack',
        };
    }

    /**
     * The mailer in config/mail.php this provider sends through.
     */
    public function mailer(): string
    {
        return $this->value;
    }

    /**
     * The .env entries the provider needs, by the config key that reads each.
     *
     * @return array<string, string>
     */
    public function requiredEnv(): array
    {
        return match ($this) {
            self::LOG => [],
            self::RESEND => ['services.resend.key' => 'RESEND_API_KEY'],
            self::BREVO => ['services.brevo.key' => 'BREVO_API_KEY'],
            self::SMTP => ['mail.mailers.smtp.host' => 'MAIL_HOST', 'mail.mailers.smtp.port' => 'MAIL_PORT'],
        };
    }

    /**
     * Optional .env entries worth naming on the screen.
     *
     * @return list<string>
     */
    public function optionalEnv(): array
    {
        return match ($this) {
            self::SMTP => ['MAIL_USERNAME', 'MAIL_PASSWORD', 'MAIL_SCHEME'],
            default => [],
        };
    }

    /**
     * @return list<string>
     */
    public function missingEnv(): array
    {
        return collect($this->requiredEnv())
            ->filter(fn (string $env, string $key) => blank(config($key)))
            ->values()
            ->all();
    }

    public function isReady(): bool
    {
        return $this->missingEnv() === [];
    }

    public function delivers(): bool
    {
        return $this !== self::LOG;
    }
}
