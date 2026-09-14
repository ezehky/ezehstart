<?php

namespace App\Enums;

use App\Traits\WithEnumHelpers;

/**
 * What a saved section is for. Drives the "Saved" group in the block palette (Saved
 * Header / Saved Footer / Saved CTA / Saved Promotional Section) and which section a
 * campaign's "Footer" picker offers.
 */
enum EmailSectionTypeEnum: string
{
    use WithEnumHelpers;

    case HEADER = 'header';
    case FOOTER = 'footer';
    case CTA = 'cta';
    case PROMO = 'promo';
    case CUSTOM = 'custom';

    public function isHeader(): bool
    {
        return $this === self::HEADER;
    }

    public function isFooter(): bool
    {
        return $this === self::FOOTER;
    }

    public function isCta(): bool
    {
        return $this === self::CTA;
    }

    public function isPromo(): bool
    {
        return $this === self::PROMO;
    }

    public function isCustom(): bool
    {
        return $this === self::CUSTOM;
    }
}
