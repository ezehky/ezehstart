<?php

namespace App\Enums;

use App\Traits\WithEnumHelpers;

/**
 * Who may see an image in the library.
 *
 * Visibility is carried by the image itself and never inherited from its folder:
 * folders are labels people reorganise freely, and permissions that move when a
 * file is dragged somewhere are permissions nobody can reason about.
 */
enum ImageVisibilityEnum: string
{
    use WithEnumHelpers;

    /** Only the uploader — and any administrator — can see it. */
    case PRIVATE = 'private';

    /** Everyone holding the role named in images.visible_to_role. */
    case ROLE = 'role';

    /** Anyone signed in, and anywhere the image is rendered publicly. */
    case PUBLIC = 'public';

    public function isPrivate(): bool
    {
        return $this === self::PRIVATE;
    }

    public function isRole(): bool
    {
        return $this === self::ROLE;
    }

    public function isPublic(): bool
    {
        return $this === self::PUBLIC;
    }

    /**
     * Only ROLE reads images.visible_to_role — the other two cases must leave it
     * null so a later visibility change cannot silently re-expose an old audience.
     */
    public function needsRole(): bool
    {
        return $this === self::ROLE;
    }

    public function description(): string
    {
        return match ($this) {
            self::PRIVATE => 'Only you and administrators can see this image.',
            self::ROLE => 'Everyone with the selected role can see and reuse this image.',
            self::PUBLIC => 'Anyone can see this image, including on public pages.',
        };
    }
}
