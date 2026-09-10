<?php

namespace App\Models;

use App\Enums\StatusDefault;
use App\Traits\WithDynamicModelFormatting;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

#[Unguarded]
class Tag extends Model
{
    use WithDynamicModelFormatting;

    protected function casts(): array
    {
        return [
            'status' => StatusDefault::class,
        ];
    }

    // Getters

    public function label(): string
    {
        return $this->name;
    }

    // Relationships

    /**
     * See the note on Category::posts() — the pivot has created_at only and the
     * database fills it, so withTimestamps() would write a column that is not there.
     */
    public function posts(): MorphToMany
    {
        return $this->morphedByMany(Post::class, 'taggable');
    }

    // Scopes

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('status', StatusDefault::ACTIVE);
    }

    #[Scope]
    protected function alphabetical(Builder $query): void
    {
        $query->orderBy('name');
    }
}
