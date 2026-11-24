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
     * A member's library holds only what they uploaded, so a member browses
     * their own folders and no others.
     */
    public function isVisibleTo(User $user): bool
    {
        // A member browses their own folders only; an administrator browses all.
        return $user->isAdmin() || $this->user_id === $user->id;
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
     * The folders this user may browse. A member gets their own and nothing
     * else. An administrator browses the lot — the library is also the
     * site's media manager, and hiding folders from the person maintaining the
     * site hides the site from them.
     */
    #[Scope]
    protected function browsableBy(Builder $query, User $user, ?User $owner = null): void
    {
        if ($owner) {
            $query->where('user_id', $owner->id);

            return;
        }

        if ($user->isAdmin()) {
            // Null is a shared folder, which belongs to the platform rather than
            // to a person, so it stays on the admin's screen.
            $query->where(fn (Builder $inner) => $inner
                ->whereNull('user_id')
                ->orWhereHas('user', fn (Builder $account) => $account->where('user_type', UserTypeEnum::ADMIN)));

            return;
        }

        // A member sees what they uploaded and nothing else. The site's media is
        // not theirs to browse, however it is marked.
        $query->where('user_id', $user->id);
    }
}
