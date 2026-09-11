<?php

namespace App\Services;

use App\Enums\StatusPost;
use App\Models\Category;
use App\Models\Post;
use App\Models\Tag;
use App\Traits\WithRichTextSanitizer;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Posts, and the polymorphic taxonomy they hang off.
 */
#[Singleton]
class BlogService
{
    use WithRichTextSanitizer;

    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // TAXONOMY

    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // PUBLISHING

    /**
     * Move a post to a status, stamping published_at the first time it goes live.
     *
     * The stamp is set once and left alone afterwards, so fixing a typo three
     * months later does not shove the post back to the top of the feed.
     */
    public function applyStatus(Post $post, StatusPost $status): void
    {
        $post->status = $status;

        if ($status->isPublished() && $post->published_at === null) {
            $post->published_at = now();
        }
    }

    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // PUBLIC READS

    /**
     * The public feed. Everything the site shows a reader starts here.
     */
    public function publishedQuery(?Category $category = null, ?Tag $tag = null, ?string $search = null): Builder
    {
        return Post::query()
            ->live()
            ->with(['image', 'user', 'categories', 'tags'])
            ->when($category, fn (Builder $query) => $query->inCategory($category))
            ->when($tag, fn (Builder $query) => $query->taggedWith($tag))
            ->when($search, fn (Builder $query) => $query->where(fn (Builder $inner) => $inner
                ->searchMacro(['title', 'excerpt'], $search)))
            ->newestFirst();
    }

    /**
     * Count a read.
     *
     * Deliberately not a model event and deliberately not inside a transaction:
     * an increment is a single atomic statement, it must not touch updated_at,
     * and a view counter is never worth failing a page render over.
     */
    public function recordView(Post $post): void
    {
        // toBase() drops to the query builder, which is what keeps this off
        // updated_at — the Eloquent builder's increment() stamps it, and a reader
        // opening a page is not an edit to the post.
        Post::query()->whereKey($post->id)->toBase()->increment('views');
    }

    /**
     * Posts to read next: same categories first, newest otherwise.
     *
     * @return Collection<int, Post>
     */
    public function relatedTo(Post $post, int $limit = 3): Collection
    {
        $categoryIds = $post->categories->pluck('id');

        $related = $categoryIds->isEmpty()
            ? collect()
            : $this->publishedQuery()
                ->whereKeyNot($post->id)
                ->whereHas('categories', fn (Builder $query) => $query->whereIn('categories.id', $categoryIds))
                ->limit($limit)
                ->get();

        if ($related->count() >= $limit) {
            return $related;
        }

        // Topping up rather than showing two cards where three fit.
        return $related->concat(
            $this->publishedQuery()
                ->whereKeyNot($post->id)
                ->whereKeyNot($related->pluck('id')->all() ?: [0])
                ->limit($limit - $related->count())
                ->get()
        )->take($limit);
    }
}
