<?php

namespace App\Services;

use App\Enums\CategoryGroupEnum;
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
}
