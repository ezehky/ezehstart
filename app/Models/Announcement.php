<?php

namespace App\Models;

use App\Enums\AnnouncementLayoutEnum;
use App\Enums\StatusDefault;
use App\Enums\StatusYes;
use App\Traits\WithDynamicModelFormatting;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Unguarded]
class Announcement extends Model
{
    use WithDynamicModelFormatting;

    protected function casts(): array
    {
        return [
            'show_newsletter' => StatusYes::class,
            'layout' => AnnouncementLayoutEnum::class,
            'status' => StatusDefault::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    // Getters

    public function showsNewsletter(): bool
    {
        return $this->show_newsletter === StatusYes::YES;
    }

    /**
     * Nothing but the picture: no copy and no form. The popup drops its padding
     * and shows the image edge to edge.
     */
    public function isImageOnly(): bool
    {
        return $this->image_id && ! $this->title && ! $this->body && ! $this->showsNewsletter();
    }

    /**
     * An icon stands in for the picture only when there is no picture. The admin
     * screen keeps them apart, but a row written some other way should still
     * render one thing, not both.
     */
    public function showsIcon(): bool
    {
        return $this->icon && ! $this->image_id;
    }

    /**
     * Whether it would be shown right now, for the admin listing's badge. The
     * same test as the live() scope, asked of one row.
     */
    public function isLive(): bool
    {
        return $this->status->isActive()
            && (! $this->starts_at || $this->starts_at->isPast())
            && (! $this->ends_at || $this->ends_at->isFuture());
    }

    /**
     * Changes whenever the announcement does, so an edited popup is shown again to
     * a visitor who dismissed the previous wording.
     */
    public function dismissKey(): string
    {
        return "announcement-{$this->id}-{$this->updated_at?->timestamp}";
    }

    // Relationships

    public function image(): BelongsTo
    {
        return $this->belongsTo(Image::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // Scopes

    /**
     * Switched on and inside its window.
     */
    #[Scope]
    protected function live(Builder $query): void
    {
        $query->where('status', StatusDefault::ACTIVE)
            ->where(fn (Builder $inner) => $inner->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn (Builder $inner) => $inner->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }
}
