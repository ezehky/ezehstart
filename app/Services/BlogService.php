<?php

namespace App\Services;

use App\Enums\CategoryGroupEnum;
use App\Enums\StatusDefault;
use App\Enums\StatusPost;
use App\Models\Category;
use App\Models\Post;
use App\Models\Tag;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Posts, and the polymorphic taxonomy they hang off.
 */
#[Singleton]
class BlogService
{
    /**
     * The tags on the HTML a post may contain.
     *
     * Tiptap sends HTML rather than markdown, and HTML from a form is untrusted
     * however trusted the person typing it is — an author account is exactly what
     * an attacker would go for to get a script onto every reader's page.
     */
    private const ALLOWED_TAGS = '<p><br><strong><b><em><i><u><s><a><ul><ol><li><h2><h3><h4><blockquote><code><pre><img><hr><figure><figcaption><table><thead><tbody><tr><th><td>';

    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // CONTENT

    /**
     * Clean editor HTML before it is stored.
     *
     * strip_tags handles the elements; the regex pass removes the two attribute
     * families that carry script — inline handlers and javascript: URLs — which
     * strip_tags leaves alone on tags it keeps.
     */
    public function sanitize(?string $html): string
    {
        $clean = strip_tags((string) $html, self::ALLOWED_TAGS);

        // on* handlers, quoted or not.
        $clean = preg_replace('/\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $clean) ?? '';

        // javascript:, vbscript: and data: URLs in href/src.
        $clean = preg_replace(
            '/\s(href|src)\s*=\s*("|\')?\s*(javascript|vbscript|data):[^"\'>\s]*("|\')?/i',
            ' $1="#"',
            $clean
        ) ?? '';

        return trim($clean);
    }

    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // TAXONOMY

    /**
     * Turn a comma-separated tag box into tag rows, making any that are new.
     *
     * Matched on the slug rather than the name, so "Skin Care" and "skin care"
     * are the same tag rather than two that look identical in a list.
     *
     * @return Collection<int, Tag>
     */
    public function resolveTags(string $input): Collection
    {
        return collect(explode(',', $input))
            ->map(fn (string $name) => trim($name))
            ->filter()
            ->unique(fn (string $name) => kSlug($name))
            ->map(fn (string $name) => Tag::query()->firstOrCreate(
                ['slug' => kSlug($name)],
                ['name' => $name, 'status' => StatusDefault::ACTIVE]
            ))
            ->values();
    }

    /**
     * @return Collection<int, Category>
     */
    public function categoriesFor(CategoryGroupEnum $group): Collection
    {
        return Category::query()->active()->inGroup($group)->inFlowOrder()->get();
    }

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
                ->where('title', 'like', '%'.$search.'%')
                ->orWhere('excerpt', 'like', '%'.$search.'%')))
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
