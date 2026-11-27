<?php

namespace App\Services;

use App\Enums\PolicyTypeEnum;
use App\Models\Post;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * What the site tells search engines about itself: robots.txt and the sitemap.
 *
 * Both are written by the app rather than kept in public/, because a crawler
 * wants the sitemap's full address and a static file cannot know the domain the
 * site is served from. The same build then works on staging and live alike.
 *
 * Neither names a workspace. A Disallow line is a public list of the paths a
 * site would rather nobody looked at, which for the admin prefix is the one
 * thing worth keeping quiet; the workspaces answer every request with a noindex
 * header instead (NoIndexMiddleware), and sit behind a sign-in regardless.
 */
#[Singleton]
class SearchEngineService
{
    /** Cache key for the sitemap's URL list. */
    public const SITEMAP_CACHE = 'sitemap.urls';

    /**
     * robots.txt, line by line. Everything is open; the sitemap does the work of
     * saying what is worth reading.
     */
    public function robots(): string
    {
        $lines = [
            'User-agent: *',
            'Allow: /',
            '',
            'Sitemap: '.route('sitemap'),
        ];

        return implode("\n", $lines)."\n";
    }

    /**
     * Every public page worth a crawl, built from the same rows the public pages
     * show — live posts and the legal pages — so nothing private, drafted or
     * scheduled is ever listed. Cached for an hour because it is read by
     * crawlers, not people, and a crawler does not come back sooner than that.
     *
     * @return Collection<int, array{loc: string, lastmod?: string|null, priority: string}>
     */
    public function sitemapUrls(): Collection
    {
        return Cache::remember(self::SITEMAP_CACHE, now()->addHour(), fn () => collect([
            ['loc' => route('home'), 'priority' => '1.0'],
            ['loc' => route('blog.index'), 'priority' => '0.8'],
        ])
            ->merge(Post::query()->live()->latest('published_at')->get(['id', 'slug', 'updated_at'])->map(fn (Post $post) => [
                'loc' => route('blog.show', $post),
                'lastmod' => $post->updated_at?->toAtomString(),
                'priority' => '0.7',
            ]))
            // Every legal page renders whether or not a version has been
            // published — the default title stands in — so each one is listed.
            ->merge(collect(PolicyTypeEnum::cases())->map(fn (PolicyTypeEnum $type) => [
                'loc' => $type->url(),
                'priority' => '0.3',
            ]))
            ->values());
    }
}
