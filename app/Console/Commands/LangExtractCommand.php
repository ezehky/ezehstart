<?php

namespace App\Console\Commands;

use App\Services\TranslationFileService;
use Illuminate\Console\Command;

class LangExtractCommand extends Command
{
    protected $signature = 'lang:extract';

    protected $description = 'Collect every __() string in app/ and resources/views into lang/en.json';

    public function handle(): int
    {
        $service = app(TranslationFileService::class);

        $keys = $service->extract([app_path(), resource_path('views')]);
        $added = $service->mergeSource($keys);

        $this->components->info(sprintf(
            '%d strings found, %d new. lang/%s.json is up to date — run lang:translate {locale} next.',
            \count($keys),
            $added,
            TranslationFileService::SOURCE,
        ));

        return self::SUCCESS;
    }
}
