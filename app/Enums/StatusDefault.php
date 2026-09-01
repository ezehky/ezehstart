<?php

namespace App\Enums;

use App\Traits\WithEnumHelpers;

enum StatusDefault: int
{
    use WithEnumHelpers;

    case ACTIVE = 1;
    case INACTIVE = 0;

    public function isActive(): bool
    {
        return $this === self::ACTIVE;
    }

    public function isInactive(): bool
    {
        return $this === self::INACTIVE;
    }
}
