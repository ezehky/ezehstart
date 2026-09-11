<?php

namespace App\Models;

use App\Enums\MediaVisibilityEnum;
use App\Enums\StatusDefault;
use App\Enums\UserTypeEnum;
use App\Traits\WithDynamicModelFormatting;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Unguarded]
class VideoFolder extends Model
{
    use WithDynamicModelFormatting;

    protected function casts(): array
    {
        return [
            'visibility' => MediaVisibilityEnum::class,
            'visible_to_type' => UserTypeEnum::class,
            'status' => StatusDefault::class,
        ];
    }

    // Getters

    /**
     * A folder with no owner belongs to the platform and everybody can browse it.
     */
    public function isShared(): bool
    {
        return $this->user_id === null;
    }

    /**
     * Whether this account may browse the folder.
     *
     * This gates the folder, not the videos in it. A video carries its own
     * visibility and keeps it wherever it is filed, so a folder somebody may not
     * browse can still hold a video they are allowed to see through the library
     * root — which is the intended behaviour, not a leak.
     */
    public function isVisibleTo(User $user): bool
    {
        if ($user->isAdmin() || $this->user_id === $user->id) {
            return true;
        }

        return match ($this->visibility) {
            MediaVisibilityEnum::PUBLIC => true,
            MediaVisibilityEnum::TYPE => $this->visible_to_type === $user->user_type,
            default => false,
        };
    }

    /**
     * The path from the root, for a breadcrumb. Walks parents rather than storing
     * a materialised path, because trees here are two or three deep and a stored
     * path is one more thing to keep correct when somebody moves a folder.
     */
    public function breadcrumb(): string
    {
        $names = [];
        $node = $this;
        $guard = 0;

        // The guard is not paranoia: parent_id is a plain column, and a bad import
        // or a hand-edited row can make a cycle that would otherwise hang a page.
        while ($node && $guard++ < 10) {
            array_unshift($names, $node->name);
            $node = $node->parent;
        }

        return implode(' / ', $names);
    }

    // Relationships

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function videos(): HasMany
    {
        return $this->hasMany(Video::class);
    }

    // Scopes

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('status', StatusDefault::ACTIVE);
    }

    /**
     * The top of a tree — a folder with no parent.
     */
    #[Scope]
    protected function roots(Builder $query): void
    {
        $query->whereNull('parent_id');
    }

    /**
     * The folders this user may browse: their own, plus anything whose visibility
     * lets them in. An administrator browses the lot — the library is also the
     * site's media manager, and hiding folders from the person maintaining the
     * site hides the site from them.
     */
    #[Scope]
    protected function browsableBy(Builder $query, User $user): void
    {
        if ($user->isAdmin()) {
            return;
        }

        $query->where(fn (Builder $inner) => $inner
            ->where('user_id', $user->id)
            ->orWhere('visibility', MediaVisibilityEnum::PUBLIC)
            ->orWhere(fn (Builder $typeQuery) => $typeQuery
                ->where('visibility', MediaVisibilityEnum::TYPE)
                ->where('visible_to_type', $user->user_type)));
    }
}
