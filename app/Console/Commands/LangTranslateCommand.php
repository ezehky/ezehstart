<?php

namespace App\Console\Commands;

use App\Enums\LocaleEnum;
use App\Services\TranslationFileService;
use Illuminate\Console\Command;
use Stichoza\GoogleTranslate\GoogleTranslate;

/**
 * Machine-translate the English strings into another language, once, offline.
 *
 * Deliberately a command and not something the app does on a request. The
 * package calls an unofficial Google endpoint: slow per string, rate-limited, and
 * liable to change without notice. Run here, all of that costs a developer a few
 * minutes; run on a request, it would cost every visitor and eventually take the
 * site down with it. The output is an ordinary lang file, reviewed and committed.
 *
 * stichoza/google-translate-php is a dev dependency for the same reason — a
 * production install never needs it.
 */
class LangTranslateCommand extends Command
{
    protected $signature = 'lang:translate
                            {locale : The language to write, e.g. fr}
                            {--force : Translate every string again, not just the missing ones}
                            {--delay=150 : Milliseconds between requests, to stay under the rate limit}';

    protected $description = 'Fill lang/{locale}.json (and grouped PHP files) by machine-translating the English';

    public function handle(): int
    {
        $locale = LocaleEnum::tryFrom((string) $this->argument('locale'));

        if (! $locale || $locale->isSource()) {
            $this->components->error('Pick a language from LocaleEnum other than English: '.implode(', ', array_diff(LocaleEnum::values(), [TranslationFileService::SOURCE])));

            return self::FAILURE;
        }

        if (! class_exists(GoogleTranslate::class)) {
            $this->components->error('stichoza/google-translate-php is not installed. Run: composer require stichoza/google-translate-php --dev');

            return self::FAILURE;
        }

        $service = app(TranslationFileService::class);

        // Placeholders (:name, :count) are held back from the engine so they come
        // out exactly as they went in — a translated ":nom" would print literally.
        $engine = (new GoogleTranslate)
            ->setSource(TranslationFileService::SOURCE)
            ->setTarget($locale->value)
            ->preserveParameters();

        $json = $service->missingJson($locale->value, (bool) $this->option('force'));
        $this->components->info(\count($json)." sentence(s) to translate into {$locale->label()}.");
        $service->mergeJson($locale->value, $this->translateAll($engine, $json));

        foreach ($service->groups() as $group) {
            $source = $service->readGroup(TranslationFileService::SOURCE, $group);
            $pending = $this->option('force') ? $source : array_diff_key($source, $service->readGroup($locale->value, $group));

            if ($pending === []) {
                continue;
            }

            $this->components->info(\count($pending)." message(s) in {$group}.php.");
            $service->writeGroup($locale->value, $group, $this->translateAll($engine, $pending));
        }

        $this->components->info("Done. Review lang/{$locale->value}.json before committing — machine translation is a first draft.");

        return self::SUCCESS;
    }

    /**
     * @param  array<string, string>  $strings  key => English
     * @return array<string, string> key => translated
     */
    private function translateAll(GoogleTranslate $engine, array $strings): array
    {
        $translated = [];
        $failed = 0;

        // The keys are walked rather than the array: the progress bar hands its
        // callback the item and the bar, never the key the item sat under.
        $this->withProgressBar(array_keys($strings), function (string $key) use ($engine, $strings, &$translated, &$failed) {
            try {
                $translated[$key] = $engine->translate($strings[$key]) ?? $strings[$key];
            } catch (\Throwable) {
                // Left out rather than written as English, so the next run picks it
                // up again instead of treating it as done.
                $failed++;
            }

            usleep((int) $this->option('delay') * 1000);
        });

        $this->newLine();

        if ($failed) {
            $this->components->warn("{$failed} string(s) failed — run the command again to retry just those.");
        }

        return $translated;
    }
}
