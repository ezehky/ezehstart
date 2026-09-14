<?php

namespace App\Services;

use App\Contracts\DynamicContentProvider;
use Illuminate\Container\Attributes\Singleton;

/**
 * The dynamic content sources the email builder can offer, read out of
 * config/email-content-types.php and resolved through the container. This is the
 * only place that config file is read — the block picker, the dynamic-content block
 * editor, and EmailRenderService all go through here rather than knowing the config
 * key or the provider class themselves.
 */
#[Singleton]
class DynamicContentRegistryService
{
    /**
     * @var array<string, DynamicContentProvider>|null
     */
    private ?array $resolved = null;

    /**
     * Every registered provider, keyed the way it registers itself.
     *
     * @return array<string, DynamicContentProvider>
     */
    public function providers(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $this->resolved = collect(config('email-content-types', []))
            ->map(fn (string $class) => app($class))
            ->mapWithKeys(fn (DynamicContentProvider $provider) => [$provider->key() => $provider])
            ->all();

        return $this->resolved;
    }

    public function provider(string $key): ?DynamicContentProvider
    {
        return $this->providers()[$key] ?? null;
    }

    /**
     * [key => label], for the block picker's "Content type" choice.
     *
     * @return array<string, string>
     */
    public function options(): array
    {
        return collect($this->providers())
            ->map(fn (DynamicContentProvider $provider) => $provider->label())
            ->all();
    }
}
