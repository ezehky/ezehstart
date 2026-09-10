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
     * Turn a comma-separated tag box into tag rows, making any that are new.
     *
     * Matched on the slug rather than the name, so "Skin Care" and "skin care"
     * are the same tag rather than two that look identical in a list.
     *
     * @return Collection<int, Tag>
     */
    public function resolveTags(string $input): Collection
    {
        return collect(explode(',', $input))
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
