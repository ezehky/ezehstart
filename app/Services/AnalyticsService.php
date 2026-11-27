<?php

namespace App\Services;

use App\Enums\CookieCategoryEnum;
use Illuminate\Container\Attributes\Singleton;

/**
 * Google Analytics 4, loaded only for a visitor who allowed measurement on the
 * cookie banner.
 *
 * The measurement ID (G-…) is pasted on Configuration › Site info; it is a
 * public identifier printed in every page that loads the tag, not a secret,
 * which is why it lives in the site config rather than .env. Until one is set,
 * nothing is printed at all and the banner stays a plain notice.
 *
 * The public site is all that is measured. Neither workspace loads the tag, and
 * neither does a session an administrator is viewing as a member — their clicks
 * are not a visitor's. Page views only: nothing about the visitor is sent.
 */
#[Singleton]
class AnalyticsService
{
    /** Site config group and key the ID is stored under. */
    public const CONFIG_KEY = 'analytics.ga4-id';

    public function measurementId(): ?string
    {
        $id = strtoupper(trim((string) kSiteConfig(self::CONFIG_KEY, default: '')));

        return preg_match('/^G-[A-Z0-9]{4,20}$/', $id) ? $id : null;
    }

    /**
     * What the page head needs: the ID, and whether the visitor has already
     * allowed measurement. Null when this page is never measured. With consent
     * still to come, the tag waits in the browser for the banner's
     * `cookie-consent-updated` event rather than loading.
     *
     * @return array{id: string, granted: bool}|null
     */
    public function tag(): ?array
    {
        $id = $this->measurementId();

        if (
            ! $id ||
            request()->routeIs('admin.*', 'user.*') ||
            app(ImpersonationService::class)->isImpersonating()
        ) {
            return null;
        }

        return [
            'id' => $id,
            'granted' => app(CookieConsentService::class)->granted(CookieCategoryEnum::MEASUREMENT),
        ];
    }
}
