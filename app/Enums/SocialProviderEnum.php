<?php

namespace App\Enums;

use App\Traits\WithEnumHelpers;

/**
 * The OAuth providers a user can sign in with.
 *
 * The case value is the Socialite driver name and the config/services.php key
 * both, so adding a provider is a case here plus a matching block in
 * config/services.php — nothing else has to be kept in sync by hand.
 */
enum SocialProviderEnum: string
{
    use WithEnumHelpers;

    case GOOGLE = 'google';
    case FACEBOOK = 'facebook';
    case GITHUB = 'github';

    public function isGoogle(): bool
    {
        return $this === self::GOOGLE;
    }

    public function isFacebook(): bool
    {
        return $this === self::FACEBOOK;
    }

    public function isGithub(): bool
    {
        return $this === self::GITHUB;
    }

    /**
     * Credentials live in the environment, not in the site configuration, so a
     * provider is only offered once someone has actually filled them in. Showing
     * a button that lands on a provider error page is worse than showing nothing.
     */
    public function isConfigured(): bool
    {
        return (bool) config("services.{$this->value}.client_id");
    }

    /**
     * The Flux icon rendered on the provider's sign-in button.
     */
    public function icon(): string
    {
        return match ($this) {
            self::GOOGLE => 'google',
            self::FACEBOOK => 'facebook',
            self::GITHUB => 'github',
        };
    }
}
