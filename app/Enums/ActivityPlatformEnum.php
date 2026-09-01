<?php

namespace App\Enums;

use App\Traits\WithEnumHelpers;

enum ActivityPlatformEnum: string
{
    use WithEnumHelpers;

    case WEB = 'web';
    case MOBILE = 'mobile';
}
