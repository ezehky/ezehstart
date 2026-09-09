<?php

namespace App\Enums;

use App\Traits\WithEnumHelpers;

/**
 * Where on the site a group of questions is shown.
 *
 * One case is enough for a starter kit. A second — support questions on a help
 * page, say — is a case here plus a heading and a sentence in the two match()
 * arms; the admin screen and the public section pick it up on their own.
 */
enum FaqTypeEnum: string
{
    use WithEnumHelpers;

    case GENERAL = 'general';

    public function isGeneral(): bool
    {
        return $this === self::GENERAL;
    }

    /**
     * The heading this group of questions appears under in the admin.
     */
    public function defaultTitle(): string
    {
        return match ($this) {
            self::GENERAL => 'General questions',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::GENERAL => 'Shown in the FAQ section of the landing page, in the order set below.',
        };
    }
}
