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
     * Whether an administrator has left this provider switched on.
     *
     * Credentials and permission are two different questions. Somebody may hold a
     * working Google app and still want the button off the sign-in page for a
     * while, and making them empty .env to do it is not a setting.
     *
     * The switches live together under security.social-providers, keyed by case
     * value. The key's presence is what is checked rather than its truthiness — a
     * provider deliberately turned off is a false, and asking for it with a default
     * would read that back as the default instead. An install that has never saved
     * the group offers every provider it has credentials for.
     */
    public function isEnabled(): bool
    {
        $providers = kSiteFlag('security', 'social-providers', []);

        if (! \is_array($providers) || ! \array_key_exists($this->value, $providers)) {
            return true;
        }

        return (bool) $providers[$this->value];
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
