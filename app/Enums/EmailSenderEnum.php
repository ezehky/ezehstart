<?php

namespace App\Enums;

use App\Traits\WithEnumHelpers;

enum EmailSenderEnum: string
{
    use WithEnumHelpers;

    case DEFAULT = 'default';
    case CUSTOM = 'custom'; // Domain Name will be stored here, e.g., "example.com" or "mydomain.org"

    public function isDefault(): bool
    {
        return $this === self::DEFAULT;
    }

    public function isCustom(): bool
    {
        return $this === self::CUSTOM;
    }
}
