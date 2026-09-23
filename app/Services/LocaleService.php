<?php

namespace App\Services;

use App\Enums\LocaleEnum;
use App\Models\User;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;

/**
 * Which language a request is shown in.
 *
 * The answer is looked for in order: the signed-in account's own choice, what
 * this browser picked before signing in, the site's default, and finally the
 * framework's. The first one that names a language with strings wins.
 */
#[Singleton]
class LocaleService
{
    public const SESSION_KEY = 'locale';

    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // GETTERS

    /**
     * Whether visitors are offered a choice at all. Off leaves everybody on the
     * site default, and hides every switcher.
     */
    public function isSwitcherEnabled(): bool
    {
        return (bool) kSiteFlag('localization', 'switcher', true)
            && \count(LocaleEnum::available()) > 1;
    }

    /**
     * The language the site falls back to, as long as it has strings.
     */
    public function siteDefault(): LocaleEnum
    {
        $configured = LocaleEnum::tryFrom((string) kSiteFlag('localization', 'default', config('app.locale')));

        return $configured?->hasTranslations() ? $configured : LocaleEnum::EN;
    }

    public function current(): LocaleEnum
    {
        return LocaleEnum::tryFrom(App::getLocale()) ?? LocaleEnum::EN;
    }

    /**
     * Work out the language for this visitor.
     */
    public function resolve(?User $user): LocaleEnum
    {
        if ($this->isSwitcherEnabled()) {
            foreach ([$user?->locale, session(self::SESSION_KEY)] as $candidate) {
                $locale = LocaleEnum::tryFrom((string) $candidate);

                if ($locale?->hasTranslations()) {
                    return $locale;
                }
            }
        }

        return $this->siteDefault();
    }

    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // ACTIONS

    /**
     * Apply a language to everything that formats text for this request —
     * translations, and the month and day names Carbon prints.
     */
    public function apply(LocaleEnum $locale): void
    {
        App::setLocale($locale->value);
        Carbon::setLocale($locale->value);
    }

    /**
     * Remember a visitor's choice. In the session always, so it holds before
     * sign-in; on the account as well when there is one, so it follows them to
     * another device and into the mail they are sent.
     */
    public function choose(LocaleEnum $locale, ?User $user): bool
    {
        if (! $locale->hasTranslations() || ! $this->isSwitcherEnabled()) {
            return false;
        }

        session()->put(self::SESSION_KEY, $locale->value);

        // The default is stored as null on the account, so a later change of
        // default carries it along rather than pinning it to today's.
        $user?->forceFill(['locale' => $locale === $this->siteDefault() ? null : $locale->value])->save();

        $this->apply($locale);

        return true;
    }
}
