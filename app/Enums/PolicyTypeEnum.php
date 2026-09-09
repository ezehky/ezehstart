<?php

namespace App\Enums;

use App\Traits\WithEnumHelpers;

/**
 * The legal pages the site publishes.
 *
 * Adding a case gives you a new public page for free — the route loop in
 * routes/web.php, the admin editor and the footer all iterate the cases — but the
 * three match() arms below will fail loudly until you fill them in.
 */
enum PolicyTypeEnum: string
{
    use WithEnumHelpers;

    case TERMS = 'terms';
    case PRIVACY = 'privacy';
    case COOKIES = 'cookies';

    public function isTerms(): bool
    {
        return $this === self::TERMS;
    }

    public function isPrivacy(): bool
    {
        return $this === self::PRIVACY;
    }

    public function isCookies(): bool
    {
        return $this === self::COOKIES;
    }

    /**
     * The public route that renders this policy. The case value is the route name
     * and the URL segment both, so /terms is named `terms` and nothing has to be
     * kept in sync by hand.
     */
    public function routeName(): string
    {
        return $this->value;
    }

    public function url(): string
    {
        return route($this->routeName());
    }

    /**
     * The heading a policy of this type falls back to before one is published.
     */
    public function defaultTitle(): string
    {
        return match ($this) {
            self::TERMS => 'Terms of Service',
            self::PRIVACY => 'Privacy Policy',
            self::COOKIES => 'Cookie Policy',
        };
    }

    /**
     * The shorter label used wherever the policy is linked inline — the footer and
     * the consent checkbox, where the full title reads as shouting.
     */
    public function shortLabel(): string
    {
        return match ($this) {
            self::TERMS => 'Terms',
            self::PRIVACY => 'Privacy',
            self::COOKIES => 'Cookies',
        };
    }
}
