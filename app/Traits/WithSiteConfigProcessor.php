<?php

namespace App\Traits;

use App\Services\SiteConfigurationService;

trait WithSiteConfigProcessor
{
    use WithFormResponseMessage;

    public array $config = [];

    public array $currentConfig = [];

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

        $this->currentConfig = $this->config;

        app(SiteConfigurationService::class)->update($this->config);
    }
}
