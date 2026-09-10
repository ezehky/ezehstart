<?php

namespace App\Enums;

use App\Traits\WithEnumHelpers;

/**
 * What a category is a category *of*.
 *
 * Categories are polymorphic — one table serves posts today and products or
 * anything else later — so the group is what keeps a blog category out of a
 * product picker. Slugs are unique per group, not globally: "skincare" may
 * legitimately exist in both.
 */
enum CategoryGroupEnum: string
{
    use WithEnumHelpers;

    case BLOG = 'blog';
    case PRODUCT = 'product';

    public function isBlog(): bool
    {
        return $this === self::BLOG;
    }

    public function isProduct(): bool
    {
        return $this === self::PRODUCT;
    }
}
