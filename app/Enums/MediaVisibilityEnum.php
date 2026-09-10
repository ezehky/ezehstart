<?php

namespace App\Enums;

use App\Traits\WithEnumHelpers;

/**
 * Who may see one item in a media library — an image or a video alike.
 *
 * Both libraries answer this question the same way, so they answer it with the
 * same enum: two copies would drift the first time either one gained a case, and
 * a visibility rule that means one thing for images and another for videos is a
 * rule nobody can hold in their head.
 *
 * Visibility is carried by the item itself and never inherited from its folder:
 * folders are labels people reorganise freely, and permissions that move when a
 * file is dragged somewhere are permissions nobody can reason about.
 */
enum MediaVisibilityEnum: string
{
    use WithEnumHelpers;

    /** Only the uploader — and any administrator — can see it. */
    case PRIVATE = 'private';

    /** Every account of the type named in the row's visible_to_type. */
    case TYPE = 'type';

    /** Anyone signed in, and anywhere the item is rendered publicly. */
    case PUBLIC = 'public';

    public function isPrivate(): bool
    {
        return $this === self::PRIVATE;
    }

    public function isType(): bool
    {
        return $this === self::TYPE;
    }

    public function isPublic(): bool
    {
        return $this === self::PUBLIC;
    }

    /**
     * Only TYPE reads visible_to_type — the other two cases must leave it null so
     * a later visibility change cannot silently re-expose an old audience.
     */
    public function needsType(): bool
    {
        return $this === self::TYPE;
    }

    /**
     * The sentence shown under the visibility control.
     *
     * The noun is passed in because the two libraries share this enum and "Only
     * you and administrators can see this image" is wrong on a video screen.
     */
    public function description(string $noun = 'file'): string
    {
        return match ($this) {
            self::PRIVATE => "Only you and administrators can see this {$noun}.",
            self::TYPE => "Every account of the selected type can see and reuse this {$noun}.",
            self::PUBLIC => "Anyone can see this {$noun}, including on public pages.",
        };
    }
}
