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
class Image extends Model
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
     * The public URL. Note this is filePathUrl() rather than the trait's usual
     * shorthand, because the column is file_path and the trait keys off the name.
     */
    public function url(): string
    {
        return kSafeImage($this->file_path, useStorage: true, disk: $this->disk ?? 'public');
    }

    /**
     * The URL with the last change stamped on, for the library's own tiles.
     *
     * A crop writes over the same file, so the browser would go on showing the
     * copy it already holds. Only the library uses this: a post keeps the plain
     * url(), which is the address that must never change.
     */
    public function previewUrl(): string
    {
        return $this->url().'?v='.($this->updated_at?->timestamp ?? 0);
    }

    /**
     * The size as somebody would say it, for a listing column.
     */
    public function readableSize(): string
    {
        return kFileSize((int) $this->size);
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
     * The owner always can, and a member can see nothing else. An administrator
     * sees the site's media, and a member's only through the per-member screen.
     *
     * @param  bool  $ownerScoped  True only when the caller is a screen that has
     *                             already established a right to look at this
     *                             owner's library — the admin's per-member view.
     */
    public function isVisibleTo(?User $user, bool $ownerScoped = false): bool
    {
        if (! $user) {
            return $this->visibility->isPublic();
        }

        if ($this->user_id === $user->id) {
            return true;
        }

        if ($user->isAdmin()) {
            // The same rule the visibleTo scope applies, and it has to be here
            // too: the scope keeps a member's uploads out of the grid, but this
            // is what a picker asks before accepting an id somebody sent, and a
            // row that is merely hidden is not a row that is guarded.
            return $ownerScoped || ! $this->user?->isUser();
        }

        // A member only ever sees their own uploads — the same rule as the
        // visibleTo scope, so an id sent by hand cannot reach past the grid.
        return false;
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
     * Everything the given account is allowed to pick from. A member gets their
     * own uploads and nothing else.
     *
     * An administrator sees the site's own library — images uploaded by admin
     * accounts — and deliberately not the members'. A member's uploads are
     * theirs: they do not belong in the admin picker, they are not the site's
     * media, and twenty accounts' private files mixed into one grid is not a
     * media manager. Reaching one member's library is a separate, deliberate act
     * with its own screen, which is what $owner is for.
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $user, ?User $owner = null): void
    {
        // One account's library, asked for by name. The caller has already
        // established that this viewer may look.
        if ($owner) {
            $query->where('user_id', $owner->id);

            return;
        }

        if ($user->isAdmin()) {
            $query->whereHas('user', fn (Builder $account) => $account->where('user_type', UserTypeEnum::ADMIN));

            return;
        }

        // A member sees what they uploaded and nothing else. The site's media is
        // not theirs to browse, however it is marked.
        $query->where('user_id', $user->id);
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
