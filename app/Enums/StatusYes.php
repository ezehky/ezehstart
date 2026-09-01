<?php

namespace App\Enums;

use App\Traits\WithEnumHelpers;

enum StatusYes: int
{
    use WithEnumHelpers;

    case YES = 1;
    case NO = 0;

    public function isYes(): bool
    {
        return $this === self::YES;
    }

    public function isNo(): bool
    {
        return $this === self::NO;
    }
}
