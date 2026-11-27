<?php

namespace App\Http\Controllers\Site;

use App\Enums\CategoryGroupEnum;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Post;
use App\Models\Tag;
use App\Services\BlogService;
use App\Services\CategoryService;
use App\Services\LocaleService;
use App\Services\StructuredDataService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * The public blog. Public and unauthenticated, and read-only, so a controller:
 * the list's filters ride in the query string instead of component state, which
 * makes a filtered list a link somebody can share.
 */
class BlogController extends Controller
{
    /**
     * The post list. Category, tag and search all ride in the query string, and
     * the pager keeps them.
     */
    public function index(Request $request): View
    {
        kSetSiteTitle('blog');

        $search = trim((string) $request->query('search', ''));
        $category = $request->query('category') ? (string) $request->query('category') : null;
        $tag = $request->query('tag') ? (string) $request->query('tag') : null;

        $activeCategory = $category
            ? Category::query()->active()->inGroup(CategoryGroupEnum::BLOG)->where('slug', $category)->first()
            : null;

        $activeTag = $tag ? Tag::query()->active()->where('slug', $tag)->first() : null;

        return view('site.blog.index', [
            'search' => $search,
            'category' => $category,
            'tag' => $tag,
            'activeCategory' => $activeCategory,
            'activeTag' => $activeTag,
            'categories' => app(CategoryService::class)->categoriesFor(CategoryGroupEnum::BLOG),
            'posts' => app(BlogService::class)
                ->publishedQuery($activeCategory, $activeTag, $search ?: null)
                ->paginate(9)
                ->withQueryString(),
        ]);
    }

    public function show(Post $post): View
    {
        // Bound by slug, so a draft or a scheduled post is reachable by anybody
        // who guesses the URL unless this refuses it. Administrators preview from
        // the editor, which does not come through here.
        abort_unless($post->isLive(), 404);

        // The author's profile comes along: the byline card at the foot of the post
        // is rendered from it, and lazy-loading it there would be a query per render.
        $post->load(['image', 'user.userProfile', 'categories', 'tags']);

        $service = app(BlogService::class);
        $service->recordView($post);

        kSetSiteTitle($post->metaTitleOrDefault(), format: false);

        // Set after the title, because kSetSiteTitle() only fills the metadata
        // title when one is not already there — these values are the specific
        // ones, and they should win.
        kSetMetaData(
            title: $post->metaTitleOrDefault(),
            description: $post->metaDescriptionOrDefault(),
            image: $post->image?->url(),
            type: 'article',
            url: route('blog.show', $post),
        );

        return view('site.blog.show', [
            'post' => $post,
            'related' => $service->relatedTo($post),
            'structuredData' => app(StructuredDataService::class)->article($post),
        ]);
    }

    /**
     * The RSS feed of the latest live posts, for feed readers and the newsletter
     * tools that build an email from one.
     *
     * Each item is the excerpt and a link, not the whole post: a reader is sent
     * to the page to read the rest. The same publishedQuery() the listing reads,
     * so a draft or a scheduled post cannot reach a reader before it reaches the
     * site. Twenty is what readers expect; anybody subscribed already has the
     * older ones.
     */
    public function feed(): Response
    {
        $posts = app(BlogService::class)->publishedQuery()->limit(20)->get();
        $siteName = kSiteConfig('name') ?: config('app.name');

        $xml = view('feed', [
            'posts' => $posts,
            'title' => __(':name Blog', ['name' => $siteName]),
            'description' => __('The latest posts from :name.', ['name' => $siteName]),
            'language' => app(LocaleService::class)->current()->value,
            // The newest post's date rather than now(), so an unchanged feed reads
            // as unchanged and a reader polling it is not told there is news.
            'updatedAt' => $posts->max('updated_at') ?? now(),
        ])->render();

        return response($xml, 200, ['Content-Type' => 'application/rss+xml; charset=UTF-8']);
    }
}
