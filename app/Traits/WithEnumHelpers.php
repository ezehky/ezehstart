<?php

namespace App\Traits;

trait WithEnumHelpers
{
    public function label(): string
    {
        return kBreakText($this->name);
    }

    public static function values(): array
    {
        $result = [];

        foreach (self::cases() as $case) {
            $result[] = $case->value;
        }

        return $result;
    }

    /**
     * Build a [value => label] array of enum cases.
     *
     * @param  string[]  $values
     * @param  bool[]  $isExclusives
     * @return array<string, string>
     */
    public static function forSelect(array $values = [], bool $isExclusives = false): array
    {
        $values = static::forSelectValuesArg() ?: $values;

        $result = [];

        foreach (self::cases() as $case) {
            // Exclusives
            if ($isExclusives && in_array($case->value, $values)) {
                $result[$case->value] = $case->label();

                continue;
            }

            // Exceptions
            if (! $isExclusives && ! in_array($case->value, $values, true)) {
                $result[$case->value] = $case->label();
            }
        }

        return $result;
    }

    /**
     * Default values - can be overridden or make exclusive in enums
     */
    protected static function forSelectValuesArg(): array
    {
        return [];
    }

    public function boolValue(): bool
    {
        return (bool) $this->value;
    }

    // ||||||||||||||||||||||||||||||||||||||||||||||||
    // COLOR

    public function color(): string
    {
        $map = config('_setups.status-color-map');
        $type = data_get($map, kSlug($this->name), 'soft');

        return match ($type) {
            'success' => 'green',
            'danger' => 'red',
            'warning' => 'amber',
            'primary' => 'blue',
            default => 'zinc',
        };
    }

    // COLOR
    // ||||||||||||||||||||||||||||||||||||||||||||||||
}
