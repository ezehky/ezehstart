<?php

use App\Enums\CategoryGroupEnum;
use App\Enums\StatusDefault;
use App\Enums\StatusPost;
use App\Enums\UserRoleEnum;
use App\Models\Category;
use App\Models\Post;
use App\Models\Tag;
use App\Services\BlogService;
use Illuminate\Database\QueryException;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = userWithRole(UserRoleEnum::ADMIN, ['email_verified_at' => now()]);
});

function blogCategory(string $name = 'Skincare', CategoryGroupEnum $group = CategoryGroupEnum::BLOG): Category
{
    return Category::query()->create([
        'category_group' => $group,
        'name' => $name,
        'slug' => kSlug($name),
        'status' => StatusDefault::ACTIVE,
    ]);
}

function blogPost(array $attributes = []): Post
{
    return Post::query()->create([
        'user_id' => $attributes['user_id'] ?? null,
        'title' => 'A post about things',
        'slug' => 'a-post-about-things',
        'excerpt' => 'The short version.',
        'content' => '<p>The long version.</p>',
        'status' => StatusPost::PUBLISHED,
        'published_at' => now()->subDay(),
        ...$attributes,
    ]);
}

// ||||||||||||||||||||||||||||||||||||||||||||||||
// SANITISING

test('script tags are stripped from post content', function () {
    $dirty = '<p>Fine</p><script>alert(1)</script>';

    expect(app(BlogService::class)->sanitize($dirty))
        ->toBe('<p>Fine</p>alert(1)')
        ->not->toContain('<script');
});

test('inline event handlers are removed from tags that survive', function () {
    $clean = app(BlogService::class)->sanitize('<p onclick="steal()">Text</p>');

    expect($clean)->not->toContain('onclick')
        ->and($clean)->toContain('<p>');
});

test('javascript urls are defused', function () {
    $clean = app(BlogService::class)->sanitize('<a href="javascript:alert(1)">Click</a>');

    expect($clean)->not->toContain('javascript:');
});

test('the formatting a writer actually uses survives', function () {
    $html = '<h2>Heading</h2><p><strong>Bold</strong> and <em>italic</em></p><ul><li>One</li></ul><img src="/x.png">';

    $clean = app(BlogService::class)->sanitize($html);

    expect($clean)->toContain('<h2>')
        ->and($clean)->toContain('<strong>')
        ->and($clean)->toContain('<li>')
        ->and($clean)->toContain('<img');
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// TAXONOMY

test('a slug is unique per group, not globally', function () {
    blogCategory('Skincare', CategoryGroupEnum::BLOG);
    blogCategory('Skincare', CategoryGroupEnum::PRODUCT);

    // The whole reason the unique index is composite: the same word means
    // different things in different groups.
    expect(Category::query()->where('slug', 'skincare')->count())->toBe(2);
});

test('the same slug twice in one group is refused', function () {
    blogCategory('Skincare');

    expect(fn () => blogCategory('Skincare'))->toThrow(QueryException::class);
});

test('categories and tags attach to a post polymorphically', function () {
    $post = blogPost(['user_id' => $this->admin->id]);
    $category = blogCategory();
    $tag = Tag::query()->create(['name' => 'winter', 'slug' => 'winter']);

    $post->categories()->attach($category);
    $post->tags()->attach($tag);

    expect($post->fresh()->categories)->toHaveCount(1)
        ->and($post->fresh()->tags)->toHaveCount(1)
        ->and($category->fresh()->posts)->toHaveCount(1);
});

test('tags typed into the box are created and reused case-insensitively', function () {
    $service = app(BlogService::class);

    $first = $service->resolveTags('Skincare, Winter');
    $second = $service->resolveTags('skincare, summer');

    expect($first)->toHaveCount(2)
        ->and($second)->toHaveCount(2)
        // "Skincare" and "skincare" are one tag, not two that look identical.
        ->and(Tag::query()->count())->toBe(3);
});

test('an empty tag box creates nothing', function () {
    expect(app(BlogService::class)->resolveTags(' , , '))->toBeEmpty()
        ->and(Tag::query()->count())->toBe(0);
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// PUBLISHING

test('a draft is not live and not in the public feed', function () {
    blogPost(['user_id' => $this->admin->id, 'status' => StatusPost::DRAFT, 'published_at' => null]);

    expect(app(BlogService::class)->publishedQuery()->count())->toBe(0);
});

test('a post scheduled for later is not live yet', function () {
    $post = blogPost([
        'user_id' => $this->admin->id,
        'status' => StatusPost::PUBLISHED,
        'published_at' => now()->addWeek(),
    ]);

    expect($post->isLive())->toBeFalse()
        ->and(app(BlogService::class)->publishedQuery()->count())->toBe(0);
});

test('publishing stamps the date once and never moves it again', function () {
    $service = app(BlogService::class);

    $post = blogPost(['user_id' => $this->admin->id, 'status' => StatusPost::DRAFT, 'published_at' => null]);

    $service->applyStatus($post, StatusPost::PUBLISHED);
    $post->save();

    $first = $post->fresh()->published_at;

    expect($first)->not->toBeNull();

    // Editing a typo three months later must not shove it back up the feed.
    $service->applyStatus($post, StatusPost::PUBLISHED);
    $post->save();

    expect($post->fresh()->published_at->timestamp)->toBe($first->timestamp);
});

test('an archived post drops out of the feed but keeps its row', function () {
    $post = blogPost(['user_id' => $this->admin->id]);

    $post->update(['status' => StatusPost::ARCHIVED]);

    expect(app(BlogService::class)->publishedQuery()->count())->toBe(0)
        ->and(Post::query()->count())->toBe(1);
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// PUBLIC PAGES

test('the blog index lists live posts', function () {
    blogPost(['user_id' => $this->admin->id]);

    $this->get(route('blog.index'))->assertSuccessful()->assertSee('A post about things');
});

test('a post page renders and counts the read', function () {
    $post = blogPost(['user_id' => $this->admin->id]);

    $this->get(route('blog.show', $post))->assertSuccessful()->assertSee('The long version', false);

    expect($post->fresh()->views)->toBe(1);
});

test('counting a read does not touch updated_at', function () {
    $post = blogPost(['user_id' => $this->admin->id]);

    $before = $post->updated_at;

    app(BlogService::class)->recordView($post);

    // A reader opening a page is not an edit to the post.
    expect($post->fresh()->updated_at->timestamp)->toBe($before->timestamp);
});

test('a draft cannot be reached by guessing its url', function () {
    $post = blogPost([
        'user_id' => $this->admin->id,
        'status' => StatusPost::DRAFT,
        'published_at' => null,
    ]);

    $this->get(route('blog.show', $post))->assertNotFound();
});

test('filtering by category narrows the feed', function () {
    $category = blogCategory();

    $inCategory = blogPost(['user_id' => $this->admin->id, 'slug' => 'in-category']);
    $inCategory->categories()->attach($category);

    blogPost(['user_id' => $this->admin->id, 'slug' => 'not-in-category', 'title' => 'Unrelated']);

    expect(app(BlogService::class)->publishedQuery($category)->count())->toBe(1);
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// THE ADMIN SCREENS

test('an admin can open every blog screen', function (string $route) {
    $this->actingAs($this->admin)->get(route($route))->assertSuccessful();
})->with([
    'admin.blog.posts',
    'admin.blog.post-create',
    'admin.blog.categories',
    'admin.blog.tags',
]);

test('a member cannot reach the blog admin', function () {
    $member = userWithRole(UserRoleEnum::USER, ['email_verified_at' => now()]);

    $this->actingAs($member)->get(route('admin.blog.posts'))->assertNotFound();
});

test('writing a post through the editor saves it with its taxonomy', function () {
    $category = blogCategory();

    Livewire::actingAs($this->admin)
        ->test('pages::admin.blog.post-edit')
        ->set('title', 'My first post')
        ->set('slug', 'my-first-post')
        ->set('excerpt', 'A short line.')
        ->set('content', '<p>Body text goes here.</p>')
        ->set('category_ids', [$category->id])
        ->set('tags_input', 'winter, skincare')
        ->set('status', StatusPost::PUBLISHED->value)
        ->call('save')
        ->assertHasNoErrors();

    $post = Post::query()->where('slug', 'my-first-post')->first();

    expect($post)->not->toBeNull()
        ->and($post->categories)->toHaveCount(1)
        ->and($post->tags)->toHaveCount(2)
        ->and($post->published_at)->not->toBeNull()
        ->and($post->read_minutes)->toBeGreaterThan(0);
});

test('the editor sanitises what it stores', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.blog.post-edit')
        ->set('title', 'Nasty')
        ->set('slug', 'nasty')
        ->set('excerpt', 'Short.')
        ->set('content', '<p>Fine</p><script>alert(1)</script>')
        ->set('status', StatusPost::DRAFT->value)
        ->call('save')
        ->assertHasNoErrors();

    expect(Post::query()->where('slug', 'nasty')->value('content'))->not->toContain('<script');
});

test('two posts cannot share a slug', function () {
    blogPost(['user_id' => $this->admin->id, 'slug' => 'taken']);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.blog.post-edit')
        ->set('title', 'Another')
        ->set('slug', 'taken')
        ->set('excerpt', 'Short.')
        ->set('content', '<p>Body.</p>')
        ->set('status', StatusPost::DRAFT->value)
        ->call('save')
        ->assertHasErrors('slug');
});
