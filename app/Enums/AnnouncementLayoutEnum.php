<?php

namespace App\Enums;

use App\Traits\WithEnumHelpers;

/**
 * Where the picture sits in the announcement popup.
 *
 * Only matters while the newsletter form or some copy shares the card with it —
 * an image-only announcement is the picture and nothing else, whichever is set.
 */
enum AnnouncementLayoutEnum: string
{
    use WithEnumHelpers;

    // The picture across the top, the copy and form beneath it. The narrower card,
    // and the one that reads best on a phone.
    case STACKED = 'stacked';

    // Copy and form on one side, the picture on the other. Wider, so it drops back
    // to stacked below the small breakpoint.
    case SPLIT = 'split';

    public function isStacked(): bool
    {
        return $this === self::STACKED;
    }

    public function isSplit(): bool
    {
        return $this === self::SPLIT;
    }

    public function description(): string
    {
        return match ($this) {
            self::STACKED => 'Picture on top, copy and form below.',
            self::SPLIT => 'Copy and form beside the picture.',
        };
    }
}
