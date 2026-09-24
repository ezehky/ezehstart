<?php

namespace App\Enums;

use App\Traits\WithEnumHelpers;

/**
 * The languages the interface can be shown in.
 *
 * A case is a language the kit knows how to *describe* — its name in itself, its
 * flag, its writing direction. It is only *offered* once lang/{value}.json exists,
 * because a language with no strings is English with a different flag on it. So
 * adding one is a case here and then:
 *
 *     php artisan lang:translate fr
 *
 * English is the source language and needs no file — every key is its own English.
 */
enum LocaleEnum: string
{
    use WithEnumHelpers;

    case EN = 'en';
    case FR = 'fr';
    case ES = 'es';
    case PT = 'pt';
    case DE = 'de';
    case AR = 'ar';

    /**
     * The language's name in that language. A switcher lists "Français" rather
     * than "French", because the person looking for it may not read English.
     */
    public function label(bool $lowercase = false): string
    {
        $label = match ($this) {
            self::EN => 'English',
            self::FR => 'Français',
            self::ES => 'Español',
            self::PT => 'Português',
            self::DE => 'Deutsch',
            self::AR => 'العربية',
        };

        return $lowercase ? mb_strtolower($label) : $label;
    }

    /**
     * An emoji flag, for the switcher. A language is not a country, so this is
     * the country most readers would recognise it by rather than a claim about
     * where it is spoken.
     */
    public function flag(): string
    {
        return match ($this) {
            self::EN => 'US',
            self::FR => 'FR',
            self::ES => 'ES',
            self::PT => 'PT',
            self::DE => 'DE',
            self::AR => 'SA',
        };
    }

    public function isRtl(): bool
    {
        return $this === self::AR;
    }

    public function direction(): string
    {
        return $this->isRtl() ? 'rtl' : 'ltr';
    }

    public function isSource(): bool
    {
        return $this === self::EN;
    }

    /**
     * Whether there are strings to show in this language.
     */
    public function hasTranslations(): bool
    {
        return $this->isSource() || is_file($this->path());
    }

    /**
     * Where this language's strings live.
     */
    public function path(): string
    {
        return lang_path("{$this->value}.json");
    }

    /**
     * The languages that can actually be switched to.
     *
     * @return array<int, self>
     */
    public static function available(): array
    {
        return array_values(array_filter(self::cases(), fn (self $locale) => $locale->hasTranslations()));
    }
}
