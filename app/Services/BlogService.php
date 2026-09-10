<?php

namespace App\Services;

use App\Enums\StatusPost;
use App\Enums\VideoProviderEnum;
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
    private const ALLOWED_TAGS = '<p><br><strong><b><em><i><u><s><a><ul><ol><li><h2><h3><h4><blockquote><code><pre><img><hr><figure><figcaption><table><thead><tbody><tr><th><td><iframe>';

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

        // Last, so nothing above can rewrite what it produces.
        $clean = $this->rebuildEmbeds($clean);

        return trim($clean);
    }

    /**
     * Replace every iframe with one this application built.
     *
     * strip_tags keeps the attributes on a tag it allows, and an iframe is the one
     * element where that is not survivable: a src nobody checked is a frame on our
     * page pointing at someone else's, and sandbox, allow and referrerpolicy are
     * all attributes an author could otherwise set for us.
     *
     * So none of the author's markup survives. The src is run back through
     * VideoProviderEnum, which yields a provider and an id or nothing at all, and
     * the tag is written again from those. An iframe pointing anywhere else — any
     * host with no case in that enum — is dropped rather than cleaned, because
     * there is no version of it we can vouch for.
     */
    private function rebuildEmbeds(string $html): string
    {
        return preg_replace_callback(
            '/<iframe\b[^>]*>.*?<\/iframe>|<iframe\b[^>]*\/?>/is',
            function (array $match): string {
                preg_match('/\ssrc\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $match[0], $src);

                $url = $src[2] ?? $src[3] ?? $src[4] ?? null;
                $resolved = VideoProviderEnum::resolve($url === null ? null : html_entity_decode($url, ENT_QUOTES));

                if ($resolved === null) {
                    return '';
                }

                $embed = $resolved['provider']->embedUrl($resolved['id']);

                return '<iframe src="'.e($embed).'" loading="lazy" allowfullscreen'
                    .' referrerpolicy="strict-origin-when-cross-origin"'
                    .' allow="accelerometer; clipboard-write; encrypted-media; gyroscope; picture-in-picture"></iframe>';
            },
            $html
        ) ?? '';
    }

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
