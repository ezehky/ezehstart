<?php

namespace App\Enums;

use App\Traits\WithEnumHelpers;

enum GenderEnum: string
{
    use WithEnumHelpers;

    case MALE = 'male';
    case FEMALE = 'female';
    case OTHER = 'other';

    public function isMale(): bool
    {
        return $this === self::MALE;
    }

    public function isFemale(): bool
    {
        return $this === self::FEMALE;
    }

    public function isOther(): bool
    {
        return $this === self::OTHER;
    }
}
