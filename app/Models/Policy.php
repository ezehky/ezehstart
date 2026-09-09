<?php

namespace App\Models;

use App\Enums\PolicyTypeEnum;
use App\Enums\StatusPolicy;
use App\Enums\StatusYes;
use App\Services\PolicyContentService;
use App\Traits\WithDynamicModelFormatting;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

/**
 * One version of one legal page.
 *
 * A policy is never edited once it is published — it is superseded by a new
 * version, and the old row is archived rather than deleted. That is the whole
 * design: a consent record points at a row here, so if this text could change
 * afterwards the record would be worthless.
 */
#[Unguarded]
class Policy extends Model
{
    use WithDynamicModelFormatting;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'policy_type' => PolicyTypeEnum::class,
            'status' => StatusPolicy::class,
            'requires_consent' => StatusYes::class,
            'effective_at' => 'datetime',
        ];
    }

    // Getters

    /**
     * The body, compiled from markdown into linkable sections.
     *
     * Compiling is the expensive part of rendering a legal page, so the result is
     * cached against the row's updated_at. Editing the content changes the key and
     * the old entry falls away on its own — no observer to keep in sync, and no
     * cache to forget when a version is published.
     *
     * @return array<int, array{id: string, title: ?string, html: string}>
     */
    public function sections(): array
    {
        return Cache::rememberForever(
            "policy:{$this->id}:sections:{$this->updated_at?->getTimestamp()}",
            fn () => app(PolicyContentService::class)->sections($this->content)
        );
    }

    public function isLive(): bool
    {
        return $this->status->isPublished()
            && (! $this->effective_at || $this->effective_at->isPast());
    }

    public function requiresConsent(): bool
    {
        return $this->requires_consent->boolValue();
    }

    /**
     * Published text is what people consented to, so it is superseded, not edited.
     */
    public function canEdit(): bool
    {
        return $this->status->isDraft();
    }

    public function canPublish(): bool
    {
        return $this->status->isDraft();
    }

    public function label(): string
    {
        return "{$this->title} v{$this->version}";
    }

    // Relationships

    public function consents(): HasMany
    {
        return $this->hasMany(UserConsent::class);
    }

    // Scopes

    #[Scope]
    protected function published(Builder $query): void
    {
        $query->where('status', StatusPolicy::PUBLISHED);
    }

    /**
     * Published and past its effective date — the version actually in force.
     *
     * A policy published today to take effect next month is not yet the one people
     * are held to, and the public page must keep showing the one that is.
     */
    #[Scope]
    protected function live(Builder $query): void
    {
        $query->published()->where(fn (Builder $inner) => $inner
            ->whereNull('effective_at')
            ->orWhere('effective_at', '<=', now())
        );
    }

    #[Scope]
    protected function requiringConsent(Builder $query): void
    {
        $query->where('requires_consent', StatusYes::YES);
    }
}
