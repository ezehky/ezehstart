<?php

namespace App\Models;

use App\Enums\StatusPost;
use App\Enums\StatusYes;
use App\Traits\WithDynamicModelFormatting;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Str;

#[Unguarded]
class Post extends Model
{
    use WithDynamicModelFormatting;

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'announced_at' => 'datetime',
            'is_featured' => StatusYes::class,
            'send_email' => StatusYes::class,
            'status' => StatusPost::class,
        ];
    }

    // Getters

    /**
     * Live to the public: published, and past its publication moment. The second
     * half is what makes scheduling work — a post can be PUBLISHED with a
     * published_at in the future and still not be readable yet.
     */
    public function isLive(): bool
    {
        return $this->status->isPublished()
            && $this->published_at !== null
            && $this->published_at->isPast();
    }

    /**
     * The byline. Falls back to the site name, because an author account can be
     * deleted and the archive should not start reading "by ".
     */
    public function authorName(): string
    {
        return $this->user?->name ?: (string) kSiteConfig('name');
    }

    public function readTime(): string
    {
        return max(1, (int) $this->read_minutes).' min read';
    }

    /**
     * Estimated from the word count of the rendered text, at 200 words a minute.
     * Computed on save rather than on render — telling somebody "4 min read"
     * is not worth stripping tags off every card in a listing.
     */
    public static function estimateReadMinutes(?string $html): int
    {
        $words = str_word_count(strip_tags((string) $html));

        return max(1, (int) ceil($words / 200));
    }

    public function metaTitleOrDefault(): string
    {
        return $this->meta_title ?: $this->title;
    }

    public function metaDescriptionOrDefault(): string
    {
        return $this->meta_description ?: Str::limit(strip_tags((string) $this->excerpt), 155);
    }

    // Relationships

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function image(): BelongsTo
    {
        return $this->belongsTo(Image::class);
    }

    public function categories(): MorphToMany
    {
        return $this->morphToMany(Category::class, 'categorizable');
    }

    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable');
    }

    public function imageUsages(): MorphMany
    {
        return $this->morphMany(ImageUsage::class, 'usable');
    }

    // Scopes

    /**
     * What the public feed shows. Anything not matching this is a draft, an
     * archived post, or one scheduled for later.
     */
    #[Scope]
    protected function live(Builder $query): void
    {
        $query->where('status', StatusPost::PUBLISHED)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    /**
     * Scheduled posts whose moment has come.
     *
     * Unbounded at the far end deliberately: a post that should have gone out
     * during an outage still should go out, unlike a reminder about a date that
     * has already passed.
     */
    #[Scope]
    protected function dueForPublishing(Builder $query): void
    {
        $query->where('status', StatusPost::SCHEDULED)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    /**
     * Live posts nobody has been told about yet.
     */
    #[Scope]
    protected function unannounced(Builder $query): void
    {
        $query->live()->whereNull('announced_at');
    }

    #[Scope]
    protected function featured(Builder $query): void
    {
        $query->where('is_featured', StatusYes::YES);
    }

    #[Scope]
    protected function newestFirst(Builder $query): void
    {
        $query->orderByDesc('published_at')->orderByDesc('id');
    }

    #[Scope]
    protected function inCategory(Builder $query, Category $category): void
    {
        $query->whereHas('categories', fn (Builder $inner) => $inner->where('categories.id', $category->id));
    }

    #[Scope]
    protected function taggedWith(Builder $query, Tag $tag): void
    {
        $query->whereHas('tags', fn (Builder $inner) => $inner->where('tags.id', $tag->id));
    }
}
