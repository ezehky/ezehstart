<?php

namespace App\Services;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;

/**
 * The lang/ files — finding the strings the code asks for, and writing them out.
 *
 * Two shapes live there. lang/{locale}.json holds the sentences wrapped in __()
 * across the app, keyed by their own English. lang/{locale}/*.php holds Laravel's
 * grouped messages — validation, passwords, pagination — keyed by name. The
 * lang:extract and lang:translate commands are thin wrappers over this.
 *
 * Nothing here runs on a request. The files are written once, by a developer,
 * and committed; at runtime Laravel reads them like any other lang file.
 */
#[Singleton]
class TranslationFileService
{
    public const SOURCE = 'en';

    /**
     * __('…'), trans('…'), trans_choice('…') and @lang('…') with a literal first
     * argument. A variable argument — __($title) — has no text to extract, and
     * is the caller's job to make sure is itself a key somewhere.
     */
    private const CALL_PATTERN = '/(?:\b__|\btrans|\btrans_choice|@lang)\(\s*([\'"])((?:\\\\.|(?!\1).)*?)\1/s';

    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // EXTRACT

    /**
     * Every sentence the code wraps, in the order found, without duplicates.
     *
     * Group keys — 'validation.required', 'passkeys::passkeys.invalid' — are left
     * out: those live in the PHP files, not the JSON one.
     *
     * @param  array<int, string>  $paths
     * @return array<int, string>
     */
    public function extract(array $paths): array
    {
        $finder = Finder::create()
            ->files()
            ->in(array_filter($paths, 'is_dir'))
            ->name('*.php');

        $found = [];

        foreach ($finder as $file) {
            preg_match_all(self::CALL_PATTERN, $file->getContents(), $matches);

            foreach ($matches[2] as $key) {
                $key = stripcslashes($key);

                if ($key !== '' && ! $this->isGroupKey($key)) {
                    $found[$key] = true;
                }
            }
        }

        return array_keys($found);
    }

    /**
     * Merge newly found keys into the source JSON. Existing entries keep their
     * value; nothing is removed, because a key the scan missed — one built in a
     * variable — would otherwise vanish from every language at once.
     *
     * @param  array<int, string>  $keys
     * @return int How many keys were new.
     */
    public function mergeSource(array $keys): int
    {
        $current = $this->readJson(self::SOURCE);
        $added = 0;

        foreach ($keys as $key) {
            if (! \array_key_exists($key, $current)) {
                $current[$key] = $key;
                $added++;
            }
        }

        $this->writeJson(self::SOURCE, $current);

        return $added;
    }

    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // TRANSLATE

    /**
     * The source strings a locale does not have yet, or all of them with $all.
     *
     * @return array<string, string>
     */
    public function missingJson(string $locale, bool $all = false): array
    {
        $source = $this->readJson(self::SOURCE);
        $target = $this->readJson($locale);

        return $all ? $source : array_diff_key($source, $target);
    }

    /**
     * @param  array<string, string>  $translations
     */
    public function mergeJson(string $locale, array $translations): void
    {
        $this->writeJson($locale, [...$this->readJson($locale), ...$translations]);
    }

    /**
     * The grouped PHP files the source language has — validation.php and the
     * rest, once `php artisan lang:publish` has put them there.
     *
     * @return array<int, string>
     */
    public function groups(): array
    {
        $dir = lang_path(self::SOURCE);

        if (! is_dir($dir)) {
            return [];
        }

        return collect(File::files($dir))
            ->filter(fn ($file) => $file->getExtension() === 'php')
            ->map(fn ($file) => $file->getFilenameWithoutExtension())
            ->values()
            ->all();
    }

    /**
     * A group's messages flattened to dot keys, the leaves being the text.
     *
     * @return array<string, string>
     */
    public function readGroup(string $locale, string $group): array
    {
        $path = lang_path("{$locale}/{$group}.php");

        if (! is_file($path)) {
            return [];
        }

        return array_filter(
            Arr::dot((array) require $path),
            fn ($value) => \is_string($value),
        );
    }

    /**
     * @param  array<string, string>  $translations  Dot keys, as readGroup() returns them.
     */
    public function writeGroup(string $locale, string $group, array $translations): void
    {
        $merged = [...$this->readGroup($locale, $group), ...$translations];

        File::ensureDirectoryExists(lang_path($locale));

        File::put(
            lang_path("{$locale}/{$group}.php"),
            "<?php\n\n// Generated by `php artisan lang:translate {$locale}` from lang/".self::SOURCE."/{$group}.php.\n// Edit freely — a re-run only fills keys that are missing, unless --force.\n\nreturn ".
            $this->exportArray(Arr::undot($merged)).";\n",
        );
    }

    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // PRIVATE

    private function isGroupKey(string $key): bool
    {
        return ! Str::contains($key, ' ')
            && (bool) preg_match('/^[\w-]+(::[\w-]+)?\.[\w.-]+$/', $key);
    }

    /**
     * @return array<string, string>
     */
    private function readJson(string $locale): array
    {
        $path = lang_path("{$locale}.json");

        return is_file($path) ? (array) json_decode((string) file_get_contents($path), true) : [];
    }

    /**
     * Sorted, so a re-run produces a diff of what actually changed rather than a
     * reshuffle of the whole file.
     *
     * @param  array<string, string>  $strings
     */
    private function writeJson(string $locale, array $strings): void
    {
        ksort($strings, SORT_NATURAL | SORT_FLAG_CASE);

        File::ensureDirectoryExists(lang_path());

        File::put(
            lang_path("{$locale}.json"),
            json_encode($strings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n",
        );
    }

    private function exportArray(array $array, int $depth = 1): string
    {
        $pad = str_repeat('    ', $depth);
        $lines = [];

        foreach ($array as $key => $value) {
            $line = $pad.var_export($key, true).' => ';
            $line .= \is_array($value) ? $this->exportArray($value, $depth + 1) : var_export($value, true);
            $lines[] = $line.',';
        }

        return "[\n".implode("\n", $lines)."\n".str_repeat('    ', $depth - 1).']';
    }
}
