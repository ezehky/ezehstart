<?php

namespace App\Models;

use App\Enums\ImageVisibilityEnum;
use App\Enums\StatusDefault;
use App\Enums\UserRoleEnum;
use App\Traits\WithDynamicModelFormatting;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Unguarded]
class Image extends Model
{
    use WithDynamicModelFormatting;

    protected function casts(): array
    {
        return [
            'visibility' => ImageVisibilityEnum::class,
            'visible_to_role' => UserRoleEnum::class,
            'status' => StatusDefault::class,
        ];
    }

    // Getters

    /**
     * The public URL. Note this is filePathUrl() rather than the trait's usual
     * shorthand, because the column is file_path and the trait keys off the name.
     */
    public function url(): string
    {
        return kSafeImage($this->file_path, useStorage: true, disk: $this->disk ?? 'public');
    }

    /**
     * The size as somebody would say it, for a listing column.
     */
    public function readableSize(): string
    {
        $bytes = (int) $this->size;

        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($bytes < 1024) {
                return round($bytes, $unit === 'B' ? 0 : 1).' '.$unit;
            }

            $bytes /= 1024;
        }

        return round($bytes, 1).' TB';
    }

    public function dimensions(): ?string
    {
        return $this->width && $this->height ? "{$this->width} × {$this->height}" : null;
    }

    /**
     * Whether this image is attached to anything. The delete guard reads this, so
     * it counts rather than loads — an image on forty posts is still one answer.
     */
    public function isAttached(): bool
    {
        return $this->usages()->exists();
    }

    /**
     * Whether the given account may see this image.
     *
     * Owner and administrator always can. Beyond that it is the image's own
     * visibility that decides — never its folder's, so moving a file between
     * folders cannot change who can see it.
     */
    public function isVisibleTo(?User $user): bool
    {
        if (! $user) {
            return $this->visibility->isPublic();
        }

        if ($this->user_id === $user->id || $user->isAdmin()) {
            return true;
        }

        return match (true) {
            $this->visibility->isPublic() => true,
            $this->visibility->isRole() => $this->visible_to_role !== null
                && $user->hasRole($this->visible_to_role),
            default => false,
        };
    }

    // Relationships

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function imageFolder(): BelongsTo
    {
        return $this->belongsTo(ImageFolder::class);
    }

    public function usages(): HasMany
    {
        return $this->hasMany(ImageUsage::class);
    }

    // Scopes

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('status', StatusDefault::ACTIVE);
    }

    /**
     * Everything the given account is allowed to pick from: their own uploads,
     * anything public, and anything shared with a role they hold.
     *
     * Administrators skip the filter entirely — the library is also the admin's
     * media manager, and one that hides files from them is not a manager.
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        if ($user->isAdmin()) {
            return;
        }

        $roles = $user->activeRoles()->map(fn (UserRoleEnum $role) => $role->value)->all();

        $query->where(fn (Builder $inner) => $inner
            ->where('user_id', $user->id)
            ->orWhere('visibility', ImageVisibilityEnum::PUBLIC)
            ->orWhere(fn (Builder $roleQuery) => $roleQuery
                ->where('visibility', ImageVisibilityEnum::ROLE)
                ->whereIn('visible_to_role', $roles)));
    }

    /**
     * Images nothing points at, which are the only ones safe to delete.
     */
    #[Scope]
    protected function unattached(Builder $query): void
    {
        $query->whereDoesntHave('usages');
    }

    #[Scope]
    protected function newestFirst(Builder $query): void
    {
        $query->orderByDesc('created_at')->orderByDesc('id');
    }
}
