<?php

namespace App\Services;

use App\Enums\StatusDefault;
use App\Models\Tag;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Collection;

#[Singleton]
class TagService
{
    /**
     * Turn tag names into tag rows, making any that are new.
     *
     * Takes either a comma-separated box or a list of names already split — the
     * chip picker holds an array, an import or a plain text field holds a string.
     *
     * Matched on the slug rather than the name, so "Skin Care" and "skin care"
     * are the same tag rather than two that look identical in a list.
     *
     * @param  array<int, string>|string  $input
     * @return Collection<int, Tag>
     */
    public function resolveTags(array|string $input): Collection
    {
        return collect(is_array($input) ? $input : explode(',', $input))
            ->map(fn (string $name) => trim($name))
            ->filter()
            ->unique(fn (string $name) => kSlug($name))
            ->map(fn (string $name) => Tag::query()->firstOrCreate(
                ['slug' => kSlug($name)],
                ['name' => $name, 'status' => StatusDefault::ACTIVE]
            ))
            ->values();
    }
}
