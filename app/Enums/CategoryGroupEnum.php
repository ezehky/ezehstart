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

    // Get the title for the parent of a category in this group.
    public function parentTitle()
    {
        return match ($this) {
            self::BLOG => 'content',
            self::PRODUCT => 'product',
        };
    }

    // Get the morph name for a category in this group. This is used to determine
    // the relationship name for the polymorphic relation.
    public function morphName()
    {
        return match ($this) {
            self::BLOG => 'posts',
            self::PRODUCT => 'products',
        };
    }
}
