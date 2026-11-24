<?php

namespace App\Enums;

use App\Traits\WithEnumHelpers;

/**
 * The brand colours an install can start from, one per case.
 *
 * Each hex is the 400 shade — the accent the kit paints buttons, links and
 * highlights with — and BrandColorService derives the other ten around it.
 * A custom colour can be anything, which is why the service still works out
 * the button text for itself rather than trusting a preset's.
 */
enum BrandPaletteEnum: string
{
    use WithEnumHelpers;

    case TEAL = 'teal';
    case SKY = 'sky';
    case INDIGO = 'indigo';
    case VIOLET = 'violet';
    case ROSE = 'rose';
    case AMBER = 'amber';
    case CUSTOM = 'custom';

    public function label(bool $lowercase = false): string
    {
        $label = match ($this) {
            self::TEAL => 'Teal',
            self::SKY => 'Sky',
            self::INDIGO => 'Indigo',
            self::VIOLET => 'Violet',
            self::ROSE => 'Rose',
            self::AMBER => 'Amber',
            self::CUSTOM => 'Custom',
        };

        return $lowercase ? strtolower($label) : $label;
    }

    /**
     * The colour itself. Teal is the one app.css is compiled with.
     */
    public function primary(): ?string
    {
        return match ($this) {
            self::TEAL => '#2dd4b5',
            self::SKY => '#38bdf8',
            self::INDIGO => '#818cf8',
            self::VIOLET => '#a78bfa',
            self::ROSE => '#fb7185',
            self::AMBER => '#fbbf24',
            self::CUSTOM => null,
        };
    }

    public function isCustom(): bool
    {
        return $this === self::CUSTOM;
    }

    /**
     * Every preset except Custom, which has no colour of its own.
     *
     * @return list<self>
     */
    public static function presets(): array
    {
        return array_values(array_filter(self::cases(), fn (self $palette) => ! $palette->isCustom()));
    }
}
