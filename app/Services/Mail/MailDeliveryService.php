<?php

namespace App\Services\Mail;

use App\Enums\MailProviderEnum;
use App\Mail\DeliveryTestEmail;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Which provider outgoing email goes through — the admin's pick on
 * Configuration › Email delivery (`mail-delivery.provider`), log by default.
 *
 * A pick whose keys are missing from .env falls back to the log rather than
 * failing every send. The choice is applied when the app boots and again before
 * every queued job, so a long-running queue worker follows a change without a
 * restart (AppServiceProvider).
 */
#[Singleton]
class MailDeliveryService
{
    public function picked(): MailProviderEnum
    {
        try {
            return MailProviderEnum::tryFrom((string) kSiteConfig('mail-delivery', ['provider'], MailProviderEnum::LOG->value)) ?? MailProviderEnum::LOG;
        } catch (Throwable) {
            return MailProviderEnum::LOG;
        }
    }

    /**
     * What mail actually goes through: the pick, or the log while it is not ready.
     */
    public function active(): MailProviderEnum
    {
        $picked = $this->picked();

        return $picked->isReady() ? $picked : MailProviderEnum::LOG;
    }

    public function apply(): void
    {
        // Tests set their own mailer (array, or Mail::fake()); leave them be.
        if (app()->runningUnitTests()) {
            return;
        }

        config(['mail.default' => $this->active()->mailer()]);
    }

    /**
     * Send a test email now, through the provider given (or the active one), and
     * return the provider's error, or null when it was accepted. Sent rather than
     * queued, so the answer is the provider's own.
     */
    public function sendTest(string $to, ?MailProviderEnum $provider = null): ?string
    {
        $provider ??= $this->active();

        if (! $provider->isReady()) {
            return 'Missing in .env: '.implode(', ', $provider->missingEnv()).'.';
        }

        try {
            Mail::mailer($provider->mailer())->to($to)->send(new DeliveryTestEmail($provider));

            return null;
        } catch (Throwable $exception) {
            return $exception->getMessage();
        }
    }
}
