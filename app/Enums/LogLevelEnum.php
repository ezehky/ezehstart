<?php

namespace App\Enums;

use App\Traits\WithEnumHelpers;

/**
 * The eight PSR-3 levels, in the order Monolog ranks them. Values are the lowercase
 * names so a level read out of a log line ("local.ERROR") matches with tryFrom()
 * after one strtolower().
 */
enum LogLevelEnum: string
{
    use WithEnumHelpers;

    case DEBUG = 'debug';
    case INFO = 'info';
    case NOTICE = 'notice';
    case WARNING = 'warning';
    case ERROR = 'error';
    case CRITICAL = 'critical';
    case ALERT = 'alert';
    case EMERGENCY = 'emergency';

    public function isError(): bool
    {
        return \in_array($this, [self::ERROR, self::CRITICAL, self::ALERT, self::EMERGENCY], true);
    }

    public function color(): string
    {
        return match ($this) {
            self::DEBUG => 'zinc',
            self::INFO => 'blue',
            self::NOTICE => 'sky',
            self::WARNING => 'amber',
            self::ERROR => 'red',
            self::CRITICAL, self::ALERT, self::EMERGENCY => 'rose',
        };
    }
}
