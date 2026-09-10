<?php

namespace App\Models;

use App\Enums\MediaVisibilityEnum;
use App\Enums\StatusDefault;
use App\Enums\UserTypeEnum;
use App\Enums\VideoProviderEnum;
use App\Traits\WithDynamicModelFormatting;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One video in the shared library — a reference to a file on somebody else's
 * host, never a file of ours.
 */
#[Unguarded]
class Video extends Model
{
    use WithDynamicModelFormatting;

    protected function casts(): array
    {
        return [
            'provider' => VideoProviderEnum::class,
            'visibility' => MediaVisibilityEnum::class,
            'visible_to_type' => UserTypeEnum::class,
            'status' => StatusDefault::class,
        ];
    }

    // Getters

    /**
     * The player URL, rebuilt from the provider and the id on every read.
     *
     * Nothing stores this. A URL held in a column is a URL somebody can edit into
     * a frame pointing anywhere; two columns that only an allowlisted enum can
     * turn into a URL are not.
     */
    public function embedUrl(): string
    {
        return $this->provider->embedUrl($this->video_id);
    }

    /**
     * Where a person goes to watch it on the provider's own site.
     */
    public function watchUrl(): string
    {
        return $this->provider->watchUrl($this->video_id);
    }

    public function thumbnailUrl(): ?string
    {
        return $this->provider->thumbnailUrl($this->video_id);
    }

    /**
     * The running time as somebody would say it, or null where nobody typed one.
     */
    public function readableDuration(): ?string
    {
        if (! $this->duration) {
            return null;
        }

        $seconds = (int) $this->duration;
        $minutes = intdiv($seconds, 60);

        // Past an hour the minutes have to be zero-padded or 1:5:03 reads as five
        // minutes rather than five past.
        return $minutes >= 60
            ? sprintf('%d:%02d:%02d', intdiv($minutes, 60), $minutes % 60, $seconds % 60)
            : sprintf('%d:%02d', $minutes, $seconds % 60);
    }

    /**
     * Whether this video is attached to anything. The delete guard reads this, so
     * it counts rather than loads — a video on forty posts is still one answer.
     */
    public function isAttached(): bool
    {
        return $this->usages()->exists();
    }

    /**
     * Whether the given account may see this video.
     *
     * Owner and administrator always can. Beyond that it is the video's own
     * visibility that decides — never its folder's, so moving one between folders
     * cannot change who can see it.
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
            $this->visibility->isType() => $this->visible_to_type === $user->type,
            default => false,
        };
    }

    // Relationships

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function videoFolder(): BelongsTo
    {
        return $this->belongsTo(VideoFolder::class);
    }

    public function usages(): HasMany
    {
        return $this->hasMany(VideoUsage::class);
    }

    // Scopes

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('status', StatusDefault::ACTIVE);
    }

    /**
     * Everything the given account is allowed to pick from: their own videos,
     * anything public, and anything shared with their account type.
     *
     * Administrators skip the filter entirely — the library is also the admin's
     * media manager, and one that hides rows from them is not a manager.
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        if ($user->isAdmin()) {
            return;
        }

        $query->where(fn (Builder $inner) => $inner
            ->where('user_id', $user->id)
            ->orWhere('visibility', MediaVisibilityEnum::PUBLIC)
            ->orWhere(fn (Builder $typeQuery) => $typeQuery
                ->where('visibility', MediaVisibilityEnum::TYPE)
                ->where('visible_to_type', $user->type)));
    }

    /**
     * Videos nothing points at, which are the only ones safe to delete.
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
