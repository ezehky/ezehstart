<?php

namespace App\Enums;

use App\Traits\WithEnumHelpers;

enum StatusUser: int
{
    use WithEnumHelpers;

    case ACTIVE = 1;
    case SUSPENDED = 0;

    public function isActive(): bool
    {
        return $this === self::ACTIVE;
    }

    public function isSuspended(): bool
    {
        return $this === self::SUSPENDED;
    }

    public function message(): ?string
    {
        return match ($this) {
            // self::BLOCKED => 'Your account is blocked!',
            self::SUSPENDED => 'Account is suspended.',
            // self::USER_LOCKED => 'You locked your account.',
            default => null
        };
    }
}
