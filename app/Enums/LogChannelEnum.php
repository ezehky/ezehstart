<?php

namespace App\Enums;

use App\Traits\WithEnumHelpers;

enum LogChannelEnum: string
{
    use WithEnumHelpers;

    case LARAVEL = 'laravel';
    case EZEH = 'ezeh';
    case SITE_CONFIG = 'site-config';

    public function isLaravel(): bool
    {
        return $this === self::LARAVEL;
    }

    public function isEzeh(): bool
    {
        return $this === self::EZEH;
    }

    public function isSiteConfig(): bool
    {
        return $this === self::SITE_CONFIG;
    }

    /**
     * The config/logging.php channel that writes this file. Laravel's own log is the
     * framework's "single" channel; every other case is a channel of its own name.
     */
    public function channel(): string
    {
        return $this->isLaravel() ? 'single' : $this->value;
    }

    /**
     * Where the channel writes, read back out of the logging config rather than
     * rebuilt here — so a channel repointed in config is read where it actually is.
     */
    public function path(): string
    {
        return (string) config("logging.channels.{$this->channel()}.path", storage_path("logs/{$this->value}.log"));
    }
}
