<?php

namespace App\Traits;

use App\Enums\ActivityActionEnum;
use App\Services\ActivityLogService;
use App\Services\SiteConfigurationService;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

trait WithSiteConfigProcessor
{
    use WithFormResponseMessage;

    public array $config = [];

    public array $currentConfig = [];

    /**
     * Which part of the site configuration this screen edits, for the log line.
     *
     * Abstract rather than defaulted: these switches close sign-in routes and set the
     * password policy, so a new configuration screen must say what it is answerable
     * for instead of quietly logging as "something".
     */
    abstract protected function configSubject(): string;

    protected function setConfigInitial(): void
    {
        $service = app(SiteConfigurationService::class);
        $this->config = $service->getConfigs(mergeInitial: true, raw: true);
        $this->currentConfig = $service->getConfigs(raw: true);
    }

    public function mountWithSiteConfigProcessor(): void
    {
        if ($this->config === []) {
            $this->setConfigInitial();
        }
    }

    protected function saveConfig(bool $skipValidation = false): void
    {
        if (! $skipValidation) {
            $this->validate();
        }

        $this->respondPrimary(if: $this->config === $this->currentConfig);

        // ||||||||
        // Log Service. Built from the two arrays this trait already holds rather than
        // from affectedColumns(): the site configuration is a JSON file on a disk, so
        // there is no model to be dirty and nothing for that method to read. Captured
        // before the write for the same reason it is there — afterwards the two arrays
        // agree and the diff is gone.
        $affectedColumns = $this->configDiff();
        // ||||||||

        $this->currentConfig = $this->config;

        app(SiteConfigurationService::class)->update($this->config);

        // Turning the second factor off, relaxing the password policy or disabling
        // history is an administrator write like any other, and the one somebody goes
        // looking for afterwards. It was the only group of admin screens logging
        // nothing at all.
        app(ActivityLogService::class)->logActivity(
            ActivityActionEnum::CONFIG_UPDATE,
            ' site configuration: '.$this->configSubject(),
            $affectedColumns,
        );
    }

    /**
     * What moved, in the shape logActivity() expects from affectedColumns().
     *
     * Flattened to dot keys and narrowed to the keys that actually changed: a whole
     * tree dumped on every save would bury the one switch somebody flipped, and that
     * switch is the only reason anybody opens this log line.
     *
     * @return array{original: array<string, mixed>, changes: array<string, mixed>}|null
     */
    private function configDiff(): ?array
    {
        $before = Arr::dot($this->currentConfig);
        $after = Arr::dot($this->config);

        $changedKeys = collect(array_keys($before))
            ->merge(array_keys($after))
            ->unique()
            ->filter(fn ($key) => ($before[$key] ?? null) !== ($after[$key] ?? null))
            ->values();

        if ($changedKeys->isEmpty()) {
            return null;
        }

        return [
            'original' => $this->configValues($changedKeys, $before),
            'changes' => $this->configValues($changedKeys, $after),
        ];
    }

    /**
     * Pick the changed keys out of one side of the diff.
     *
     * Strings are clipped the way the model diff clips them — the JSON editor will
     * accept a value of any length, and a log entry is not a place to keep one.
     *
     * @param  Collection<int, string>  $keys
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function configValues($keys, array $values): array
    {
        return $keys
            ->mapWithKeys(function ($key) use ($values) {
                $value = $values[$key] ?? null;

                return [$key => \is_string($value) ? str()->limit($value, 1500) : $value];
            })
            ->all();
    }
}
