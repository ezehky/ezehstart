<?php

namespace App\Enums;

use App\Traits\WithEnumHelpers;

enum StatusPolicy: int
{
    use WithEnumHelpers;

    case DRAFT = 0;
    case PUBLISHED = 1;

    /**
     * A superseded version. Archived policies are never deleted — a consent record
     * points at the exact version a user accepted, and that text has to stay
     * readable for as long as the record is worth anything.
     */
    case ARCHIVED = 2;

    public function isDraft(): bool
    {
        return $this === self::DRAFT;
    }

    public function isPublished(): bool
    {
        return $this === self::PUBLISHED;
    }

    public function isArchived(): bool
    {
        return $this === self::ARCHIVED;
    }
}
