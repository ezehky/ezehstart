<?php

namespace App\Traits;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

trait WithDynamicModelFormatting
{
    public function __call($method, $parameters)
    {
        // ===== Money columns =====
        if (str_ends_with($method, 'Money')) {
            $column = Str::snake(str_replace('Money', '', $method));
            if (isset($this->{$column})) {
                return $this->formatMoney($this->{$column}, ...$parameters);
            }
        }

        // ===== Number columns =====
        elseif (str_ends_with($method, 'Number')) {
            $column = Str::snake(str_replace('Number', '', $method));
            if (isset($this->{$column})) {
                return $this->{$column} ? number_format($this->{$column}) : 0;
            }
        }

        // ===== File URL columns =====
        elseif (str_ends_with($method, 'Url')) {
            $column = Str::snake(str_replace('Url', '', $method));

            return $this->resolveFileUrl($column);
        }

        // ===== Date columns =====
        elseif (preg_match('/^([a-zA-Z][a-zA-Z0-9]*?)(Human|HumanDay|DatetimeHuman|FormatHuman|DiffForHumans)$/', $method, $matches)) {

            // Convert camelCase method into snake_case column name
            $columnBase = Str::snake($matches[1]);

            $variant = $matches[2]; // Human / DatetimeHuman / FormatHuman / DiffForHumans
            $isDatetime = $variant === 'DatetimeHuman';
            $addDaySymbol = $variant === 'HumanDay';

            // Call your helper
            return $this->dateHuman(
                $columnBase,
                $parameters[0] ?? false,
                $isDatetime,
                $variant === 'DiffForHumans',
                format: $parameters['format'] ?? null,
                addDaySymbol: $addDaySymbol
            );
        }

        // ==== Point Columns ====
        elseif (str_ends_with($method, 'Pv')) {
            $column = Str::snake(str_replace('Pv', '', $method));

            return kPointFormat($this->{$column});
        }

        // ==== DateTime Getters for Update: Columns ====
        // Datetime
        elseif (str_ends_with($method, 'DatetimeForUpdate')) {
            $column = Str::snake(str_replace('DatetimeForUpdate', '', $method));

            return $this->{$column}?->format('Y-m-d\\TH:i');
        }

        // Time
        elseif (str_ends_with($method, 'TimeForUpdate')) {
            $column = Str::snake(str_replace('TimeForUpdate', '', $method));

            return $this->{$column} ? substr($this->{$column}, 0, 5) : null;
        }
        // ==== DateTime Getters for Update: Columns ====

        // Fallback to parent __call
        return parent::__call($method, $parameters);
    }

    /**
     * Resolve a file column into a full URL or fallback image
     */
    protected function resolveFileUrl(string $column): ?string
    {
        $file = $this->{$column} ?? null;

        $altImage = \in_array($column, ['avatar']) ? 'user' : null;

        // No file? handle fallback
        if (empty($file)) {
            return $this->isImageColumn($column) ? kSafeImage(altImage: $altImage) : null;
        }

        // Already a full URL
        if (Str::startsWith($file, ['http://', 'https://'])) {
            return $file;
        }

        // Detect extension
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

        // If it's an image, ensure we use a public storage URL
        if (\in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'svg', 'webp', 'ico'])) {
            return kSafeImage($file, altImage: $altImage);
        }

        // Non-image file → return URL or # placeholder
        return Storage::url($file) ?: '#';
    }

    /**
     * Determine if column likely represents an image field
     */
    protected function isImageColumn(string $column): bool
    {
        return Str::contains($column, [
            'image',
            'avatar',
            'photo',
            'banner',
            'thumbnail',
            'logo',
            'cover',
            'picture',
            'background',
            'poster',
            'evidence',
            'flag',
        ]);
    }

    // ===== Money formatter =====
    public function formatMoney(float $value, $default = ''): string
    {
        return kMoneyFormat($value, $default);
    }

    // ===== Date formatter =====
    public function dateHuman(
        string $column = 'created_at',
        bool $user = false,
        bool $datetime = false,
        bool $diffForHumans = false,
        ?string $format = null,
        bool $addDaySymbol = false
    ): ?string {
        if (! isset($this->{$column})) {
            return null;
        }

        return kDatetimeConverter(
            $this->{$column},
            $user ? auth()->user() : null,
            dateFormat: ! $datetime,
            dtFormat: $datetime,
            diffForHumans: $diffForHumans,
            format: $format,
            addDaySymbol: $addDaySymbol
        );
    }
}
