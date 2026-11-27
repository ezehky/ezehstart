<?php

namespace App\Services;

use App\Enums\CookieCategoryEnum;
use Illuminate\Container\Attributes\Singleton;

/**
 * What the visitor allowed on the cookie banner.
 *
 * The choice is written by the banner in the browser (resources/views/components/
 * site/cookie-banner.blade.php) as one plain cookie — `v1:necessary,measurement`
 * — so it is left out of cookie encryption in bootstrap/app.php. No choice yet,
 * an old version or a banner switched off all read as strictly necessary only:
 * the site never assumes a yes.
 */
#[Singleton]
class CookieConsentService
{
    public const COOKIE = 'site_consent';

    /** Raised when the categories change, so an old choice is asked again. */
    public const VERSION = 'v1';

    public const DAYS = 180;

    /**
     * Whether the banner is shown at all — `preferences.accept-cookies`.
     */
    public function isEnabled(): bool
    {
        return (bool) kSiteFlag('preferences', 'accept-cookies', true);
    }

    /**
     * Whether the banner asks for a choice rather than giving a notice. Only
     * while there is something optional to allow — an analytics ID — since a
     * choice with one answer is not a choice.
     */
    public function asksForConsent(): bool
    {
        return $this->isEnabled() && app(AnalyticsService::class)->measurementId() !== null;
    }

    public function granted(CookieCategoryEnum $category): bool
    {
        return $category->isRequired() || in_array($category, $this->parse() ?? [], true);
    }

    /**
     * What <x-site.cookie-banner> needs, handed over by a view composer: null
     * prints nothing.
     *
     * @return array{consent: bool, cookie: string, version: string, days: int}|null
     */
    public function banner(): ?array
    {
        if (! $this->isEnabled()) {
            return null;
        }

        return [
            'consent' => $this->asksForConsent(),
            'cookie' => self::COOKIE,
            'version' => self::VERSION,
            'days' => self::DAYS,
        ];
    }

    /**
     * @return list<CookieCategoryEnum>|null
     */
    private function parse(): ?array
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $raw = (string) request()->cookie(self::COOKIE);

        if (! str_starts_with($raw, self::VERSION.':')) {
            return null;
        }

        return collect(explode(',', substr($raw, strlen(self::VERSION) + 1)))
            ->map(fn (string $value) => CookieCategoryEnum::tryFrom(trim($value)))
            ->filter()
            ->values()
            ->all();
    }
}
