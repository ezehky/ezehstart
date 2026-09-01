<?php

namespace App\Enums;

use App\Traits\WithEnumHelpers;

enum UserRoleEnum: string
{
    use WithEnumHelpers;

    case ADMIN = 'admin';
    case USER = 'user';

    public function isAdmin(): bool
    {
        return $this === self::ADMIN;
    }

    public function isUser(): bool
    {
        return $this === self::USER;
    }
}
