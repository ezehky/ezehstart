<?php

namespace App\Services;

use App\Enums\CategoryGroupEnum;
use App\Enums\StatusDefault;
use App\Models\Category;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Collection;

#[Singleton]
class CategoryService
{
    /**
     * @return Collection<int, Category>
     */
    public function categoriesFor(CategoryGroupEnum $group): Collection
    {
        return Category::query()->active()->inGroup($group)->inFlowOrder()->get();
    }

    /**
     * Turn category names into rows in one group, making any that are new.
     *
     * The tag equivalent of this is TagService::resolveTags, and it matches the
     * same way — on the slug, so "Skin Care" and "skin care" are one category. A
     * category carries two things a tag does not: a group its slug is only unique
     * inside, and a position, so a pasted batch lands in the order it was typed
     * rather than all sharing order zero.
     *
     * @param  array<int, string>|string  $input
     * @return Collection<int, Category>
     */
    public function resolveCategories(array|string $input, CategoryGroupEnum $group): Collection
    {
        $lastOrder = (int) Category::query()->where('category_group', $group)->max('flow_order');

        return collect(is_array($input) ? $input : explode(',', $input))
            ->map(fn (string $name) => trim($name))
            ->filter()
            ->unique(fn (string $name) => kSlug($name))
            ->values()
            ->map(fn (string $name, int $index) => Category::query()->firstOrCreate(
                [
                    'category_group' => $group,
                    // The group is part of the slug because that is what the single
                    // add writes; the two paths have to agree or they make twins.
                    'slug' => kSlug("{$name} {$group->value}"),
                ],
                [
                    'name' => $name,
                    'flow_order' => $lastOrder + $index + 1,
                    'status' => StatusDefault::ACTIVE,
                ]
            ));
    }
}
