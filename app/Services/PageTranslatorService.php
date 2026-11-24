<?php

namespace App\Services;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Collection;

/**
 * "Translate this page": Google's website translator behind the site's own menu.
 *
 * Not the same thing as the language switcher. That one swaps the interface for
 * a lang/{code}.json file somebody has reviewed; this one machine-translates
 * whatever is on the screen in the visitor's browser, posts and pages included,
 * which no lang file can reach. Nothing here touches the server's locale.
 *
 * An administrator chooses which languages the menu offers, by code. The name
 * and the flag come from database/data/translator-languages.json rather than
 * from the administrator, so nobody has to know that Yorùbá is "yo" or which
 * flag stands for Swahili — the system picks.
 */
#[Singleton]
class PageTranslatorService
{
    private const CATALOGUE = 'data/translator-languages.json';

    private ?Collection $catalogue = null;

    /**
     * Whether the menu is shown at all. Off by default: it loads a Google script
     * once a visitor picks a language, and that is a decision for the install.
     */
    public function enabled(): bool
    {
        return (bool) kSiteFlag('translator', 'status', false) && $this->languages()->isNotEmpty();
    }

    /**
     * Every language the translator can be offered in, keyed by Google's code.
     *
     * @return Collection<string, array{code: string, name: string, native: string, flag: string}>
     */
    public function catalogue(): Collection
    {
        return $this->catalogue ??= collect(json_decode((string) file_get_contents(database_path(self::CATALOGUE)), true) ?: [])
            ->keyBy('code');
    }

    /**
     * The languages the menu offers, in the order they were added. A code no
     * longer in the catalogue is dropped rather than shown with no name.
     *
     * @return Collection<string, array{code: string, name: string, native: string, flag: string}>
     */
    public function languages(): Collection
    {
        $catalogue = $this->catalogue();

        return collect((array) kSiteFlag('translator', 'languages', []))
            ->filter(fn ($code) => \is_string($code) && $catalogue->has($code))
            ->unique()
            ->mapWithKeys(fn (string $code) => [$code => $catalogue->get($code)]);
    }

    /**
     * The catalogue less what is already offered, for the Add select.
     *
     * @param  array<int, string>  $chosen
     * @return Collection<string, array{code: string, name: string, native: string, flag: string}>
     */
    public function addable(array $chosen): Collection
    {
        return $this->catalogue()->except($chosen)->sortBy('name');
    }

    /**
     * The flag for a code, or null for one the catalogue does not know.
     */
    public function flagFor(string $code): ?string
    {
        return $this->catalogue()->get($code)['flag'] ?? null;
    }
}
