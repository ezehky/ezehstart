<?php

namespace App\Models;

use App\Enums\CategoryGroupEnum;
use App\Enums\StatusDefault;
use App\Traits\WithDynamicModelFormatting;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

#[Unguarded]
class Category extends Model
{
    use WithDynamicModelFormatting;

    protected function casts(): array
    {
        return [
            'category_group' => CategoryGroupEnum::class,
            'status' => StatusDefault::class,
        ];
    }

    // Getters

    public function label(): string
    {
        return $this->name;
    }

    public function parentName(): string
    {
        return $this->parent?->name ?? 'Top level';
    }

    // Relationships

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function image(): BelongsTo
    {
        return $this->belongsTo(Image::class);
    }

    /**
     * No withTimestamps() here: the pivot carries created_at only, and the column
     * is useCurrent() in the migration, so the database fills it on attach. Asking
     * Eloquent to manage the pair would have it write an updated_at that does not
     * exist.
     */
    public function posts(): MorphToMany
    {
        return $this->morphedByMany(Post::class, 'categorizable');
    }

    // Scopes

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('status', StatusDefault::ACTIVE);
    }

    #[Scope]
    protected function inGroup(Builder $query, CategoryGroupEnum $group): void
    {
        $query->where('category_group', $group);
    }

    #[Scope]
    protected function roots(Builder $query): void
    {
        $query->whereNull('parent_id');
    }

    /**
     * Falls back to name so a batch all left at flow_order 0 still comes out in a
     * stable order rather than whatever the database felt like that day.
     */
    #[Scope]
    protected function inFlowOrder(Builder $query): void
    {
        $query->orderBy('flow_order')->orderBy('name');
    }
}
