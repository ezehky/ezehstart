<?php

namespace App\Enums;

use App\Traits\WithEnumHelpers;

/**
 * What a visitor can allow the site to store, from the cookie banner.
 *
 * Strictly necessary is always on — signing in and the form tokens do not work
 * without it. Measurement is Google Analytics, through AnalyticsService, and is
 * only ever asked about once a measurement ID is set on Site info; until then
 * there is nothing to allow and the banner stays a notice.
 */
enum CookieCategoryEnum: string
{
    use WithEnumHelpers;

    case NECESSARY = 'necessary';
    case MEASUREMENT = 'measurement';

    public function label(bool $lowercase = false): string
    {
        $label = match ($this) {
            self::NECESSARY => 'Strictly necessary',
            self::MEASUREMENT => 'Measurement',
        };

        return $lowercase ? mb_strtolower($label) : $label;
    }

    public function description(): string
    {
        return match ($this) {
            self::NECESSARY => 'Keeps you signed in and protects forms. The site cannot work without these.',
            self::MEASUREMENT => 'Lets Google Analytics count visits and the pages read, so we can improve the site. It never receives your name or email.',
        };
    }

    public function isRequired(): bool
    {
        return $this === self::NECESSARY;
    }
}
