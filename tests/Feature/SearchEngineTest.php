<?php

use App\Enums\StatusPost;
use App\Enums\StatusYes;
use App\Enums\UserTypeEnum;
use App\Models\Post;
use App\Services\SiteConfigurationService;

beforeEach(function () {
    app(SiteConfigurationService::class)->update(initials: true);
});

function searchablePost(array $attributes = []): Post
{
    return Post::query()->create([
        'title' => 'A post worth finding',
        'slug' => 'a-post-worth-finding',
        'excerpt' => 'The short version, for the feed.',
        'content' => '<p>The long version, for the page only.</p>',
        'is_featured' => StatusYes::NO,
        'status' => StatusPost::PUBLISHED,
        'published_at' => now()->subDay(),
        ...$attributes,
    ]);
}

// ||||||||||||||||||||||||||||||||||||||||||||||||
// ROBOTS AND THE SITEMAP

test('robots.txt points at the sitemap by its full address', function () {
    $this->get('/robots.txt')
        ->assertSuccessful()
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
        ->assertSee('Sitemap: '.route('sitemap'), escape: false);
});

test('robots.txt never names a workspace', function () {
    $body = $this->get('/robots.txt')->getContent();

    expect($body)->not->toContain('Disallow')
        ->and($body)->not->toContain('app-splash')
        ->and($body)->not->toContain('/app');
});

test('the sitemap lists live posts and leaves drafts out', function () {
    $live = searchablePost();
    $draft = searchablePost(['slug' => 'still-a-draft', 'status' => StatusPost::DRAFT]);
    $scheduled = searchablePost(['slug' => 'next-week', 'published_at' => now()->addWeek()]);

    $this->get('/sitemap.xml')
        ->assertSuccessful()
        ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
        ->assertSee(route('home'), escape: false)
        ->assertSee(route('blog.show', $live), escape: false)
        ->assertSee(route('terms'), escape: false)
        ->assertDontSee(route('blog.show', $draft), escape: false)
        ->assertDontSee(route('blog.show', $scheduled), escape: false);
});

test('both workspaces ask not to be indexed', function () {
    $admin = userOfType(UserTypeEnum::ADMIN, ['email_verified_at' => now()]);
    $member = userOfType(UserTypeEnum::USER, ['email_verified_at' => now()]);

    $this->actingAs($admin)->get(route('admin.dashboard'))
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');

    $this->actingAs($member)->get(route('user.dashboard'))
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
});

test('the public pages can be indexed', function () {
    $this->get(route('home'))->assertHeaderMissing('X-Robots-Tag');
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// STRUCTURED DATA

test('the home page describes the site to search engines', function () {
    $this->get(route('home'))
        ->assertSuccessful()
        ->assertSee('application/ld+json', escape: false)
        ->assertSee('"@type":"Organization"', escape: false);
});

test('the kit\'s placeholder artwork is never offered as the logo', function () {
    $html = $this->get(route('home'))->getContent();

    preg_match('/<script type="application\/ld\+json">(.*?)<\/script>/s', $html, $match);

    expect(json_decode($match[1], true))->not->toHaveKey('logo');
});

test('a post page describes the post', function () {
    $post = searchablePost();

    $this->get(route('blog.show', $post))
        ->assertSuccessful()
        ->assertSee('"@type":"BlogPosting"', escape: false)
        ->assertSee('"headline":"A post worth finding"', escape: false);
});

test('a title cannot close the script tag it is printed in', function () {
    $post = searchablePost(['title' => 'Tags </script><b>here</b>']);

    $this->get(route('blog.show', $post))
        ->assertSuccessful()
        ->assertDontSee('</script><b>here', escape: false);
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// RSS

test('the feed carries live posts as an excerpt and a link', function () {
    $live = searchablePost();
    $draft = searchablePost(['slug' => 'still-a-draft', 'title' => 'Not yet', 'status' => StatusPost::DRAFT]);

    $this->get(route('blog.feed'))
        ->assertSuccessful()
        ->assertHeader('Content-Type', 'application/rss+xml; charset=UTF-8')
        ->assertSee('<link>'.route('blog.show', $live).'</link>', escape: false)
        ->assertSee('The short version, for the feed.')
        ->assertDontSee('The long version, for the page only.')
        ->assertDontSee(route('blog.show', $draft), escape: false);
});

test('the feed address is not taken for a post slug', function () {
    searchablePost(['slug' => 'feed', 'title' => 'A post called feed']);

    $this->get('/blog/feed')
        ->assertHeader('Content-Type', 'application/rss+xml; charset=UTF-8');
});

test('the public pages tell a reader where the feed is', function () {
    $this->get(route('blog.index'))
        ->assertSuccessful()
        ->assertSee('type="application/rss+xml"', escape: false)
        ->assertSee(route('blog.feed'), escape: false);
});
