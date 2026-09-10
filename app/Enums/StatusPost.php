<?php

namespace App\Enums;

use App\Traits\WithEnumHelpers;

enum StatusPost: int
{
    use WithEnumHelpers;

    case DRAFT = 0;
    case PUBLISHED = 1;

    /**
     * Taken down but kept. A post that has been linked to, indexed, or cited is
     * not something to delete — archiving keeps the row and its URL history
     * intact while removing it from every listing.
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
