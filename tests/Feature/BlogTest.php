<?php

use App\Enums\CategoryGroupEnum;
use App\Enums\StatusDefault;
use App\Enums\StatusPost;
use App\Enums\StatusYes;
use App\Enums\UserRoleEnum;
use App\Models\Category;
use App\Models\Post;
use App\Models\Tag;
use App\Services\BlogService;
use App\Services\TagService;
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
        // The column is NOT NULL with a default, so a real row always has one —
        // create() would leave the in-memory model without it.
        'is_featured' => StatusYes::NO,
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

test('an image keeps the width the editor gave it', function () {
    $clean = app(BlogService::class)->sanitize('<img src="/x.png" width="420">');

    expect($clean)->toContain('width="420"');
});

test('an oversized width is clamped rather than dropped', function () {
    // The attribute survives strip_tags untouched, so a hand-edited width is the
    // one thing an author can put on an image that nothing else checks.
    $clean = app(BlogService::class)->sanitize('<img src="/x.png" width="99999">');

    expect($clean)->toContain('width="2000"');
});

test('a width that is not a plain number loses the attribute', function () {
    $clean = app(BlogService::class)->sanitize('<img src="/x.png" width="80%">');

    expect($clean)->toContain('<img')
        ->and($clean)->not->toContain('width');
});

test('an image with no width is left alone', function () {
    $clean = app(BlogService::class)->sanitize('<img src="/x.png" alt="A cat">');

    expect($clean)->toContain('src="/x.png"')
        ->and($clean)->toContain('alt="A cat"')
        ->and($clean)->not->toContain('width');
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
    $service = app(TagService::class);

    $first = $service->resolveTags('Skincare, Winter');
    $second = $service->resolveTags('skincare, summer');

    expect($first)->toHaveCount(2)
        ->and($second)->toHaveCount(2)
        // "Skincare" and "skincare" are one tag, not two that look identical.
        ->and(Tag::query()->count())->toBe(3);
});

test('an empty tag box creates nothing', function () {
    expect(app(TagService::class)->resolveTags(' , , '))->toBeEmpty()
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
    // The two screens that need a subject: the editor binds a post, and the
    // category screen is one screen per group rather than one per table.
    $parameters = match ($route) {
        'admin.blog.edit' => [blogPost(['user_id' => $this->admin->id])],
        'admin.categories' => [CategoryGroupEnum::BLOG],
        default => [],
    };

    $this->actingAs($this->admin)->get(route($route, $parameters))->assertSuccessful();
})->with([
    'admin.blog.blogs',
    'admin.blog.create',
    'admin.blog.edit',
    'admin.categories',
    'admin.tags',
]);

test('a member cannot reach the blog admin', function () {
    $member = userWithRole(UserRoleEnum::USER, ['email_verified_at' => now()]);

    $this->actingAs($member)->get(route('admin.blog.blogs'))->assertNotFound();
});

test('writing a post through the editor saves it with its taxonomy', function () {
    $category = blogCategory();

    Livewire::actingAs($this->admin)
        ->test('pages::admin.content.post-edit')
        ->set('title', 'My first post')
        ->set('excerpt', 'A short line.')
        ->set('content', '<p>Body text goes here.</p>')
        ->set('category_ids', [$category->id])
        ->set('tag_names', ['winter', 'skincare'])
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
        ->test('pages::admin.content.post-edit')
        ->set('title', 'Nasty')
        ->set('excerpt', 'Short.')
        ->set('content', '<p>Fine</p><script>alert(1)</script>')
        ->set('status', StatusPost::DRAFT->value)
        ->call('save')
        ->assertHasNoErrors();

    expect(Post::query()->where('slug', 'nasty')->value('content'))->not->toContain('<script');
});

test('two posts cannot share a title', function () {
    // The slug is derived from the title now, so the title is what has to be unique.
    blogPost(['user_id' => $this->admin->id, 'title' => 'Taken', 'slug' => 'taken']);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.content.post-edit')
        ->set('title', 'Taken')
        ->set('excerpt', 'Short.')
        ->set('content', '<p>Body.</p>')
        ->set('status', StatusPost::DRAFT->value)
        ->call('save')
        ->assertHasErrors('title');
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// ADDING IN BULK

test('a pasted list adds every tag at once', function () {
    Tag::query()->create(['name' => 'Winter', 'slug' => 'winter', 'status' => StatusDefault::ACTIVE]);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.content.tags')
        ->set('bulk_names', 'Skincare, winter, Routine')
        ->call('saveMany')
        ->assertHasNoErrors();

    // "winter" was already there, matched on its slug rather than its spelling.
    expect(Tag::query()->count())->toBe(3)
        ->and(Tag::query()->pluck('name')->all())->toContain('Skincare', 'Routine');
});

test('a pasted list of tags that all exist writes nothing', function () {
    Tag::query()->create(['name' => 'Winter', 'slug' => 'winter', 'status' => StatusDefault::ACTIVE]);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.content.tags')
        ->set('bulk_names', 'winter, WINTER')
        ->call('saveMany');

    expect(Tag::query()->count())->toBe(1);
});

test('an empty bulk box is refused', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.content.tags')
        ->set('bulk_names', '')
        ->call('saveMany')
        ->assertHasErrors('bulk_names');
});

test('a pasted list adds every category to the group, in the order typed', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.content.categories', ['category_group' => CategoryGroupEnum::BLOG])
        ->set('bulk_names', 'Skincare, Routine, Winter')
        ->call('saveMany')
        ->assertHasNoErrors();

    expect(Category::query()->inGroup(CategoryGroupEnum::BLOG)->count())->toBe(3)
        // Pasted order beats alphabetical: it is the order the admin meant.
        ->and(Category::query()->inFlowOrder()->pluck('name')->all())->toBe(['Skincare', 'Routine', 'Winter']);
});

test('a bulk category lands after whatever the group already held', function () {
    blogCategory('Existing');

    Livewire::actingAs($this->admin)
        ->test('pages::admin.content.categories', ['category_group' => CategoryGroupEnum::BLOG])
        ->set('bulk_names', 'Newcomer')
        ->call('saveMany')
        ->assertHasNoErrors();

    expect(Category::query()->where('name', 'Newcomer')->value('flow_order'))->toBe(1);
});

test('the same category name in another group is still its own row', function () {
    blogCategory('Skincare', CategoryGroupEnum::PRODUCT);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.content.categories', ['category_group' => CategoryGroupEnum::BLOG])
        ->set('bulk_names', 'Skincare')
        ->call('saveMany')
        ->assertHasNoErrors();

    expect(Category::query()->where('name', 'Skincare')->count())->toBe(2);
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// THE TAXONOMY PICKERS

test('the pickers open with what the post already carries', function () {
    $post = blogPost(['user_id' => $this->admin->id]);
    $category = blogCategory();
    $tag = Tag::query()->create(['name' => 'Winter', 'slug' => 'winter', 'status' => StatusDefault::ACTIVE]);

    $post->categories()->attach($category);
    $post->tags()->attach($tag);

    $component = Livewire::actingAs($this->admin)
        ->test('pages::admin.content.post-edit', ['post' => $post]);

    expect($component->get('category_ids'))->toBe([$category->id])
        ->and($component->get('tag_names'))->toBe(['Winter']);
});

test('the tag picker shows the library plus whatever was typed on the screen', function () {
    Tag::query()->create(['name' => 'Winter', 'slug' => 'winter', 'status' => StatusDefault::ACTIVE]);

    $component = Livewire::actingAs($this->admin)
        ->test('pages::admin.content.post-edit')
        ->set('new_tag', 'Skincare')
        ->call('addTag');

    // Ticked first: the picker collapses a long list, and what the post carries
    // has to stay above the fold.
    expect($component->instance()->tagOptions->all())->toBe(['Skincare', 'Winter'])
        ->and($component->get('tag_names'))->toBe(['Skincare'])
        ->and($component->get('new_tag'))->toBe('')
        // Nothing is written until the post is, so an abandoned form leaves no orphans.
        ->and(Tag::query()->count())->toBe(1);
});

test('typing a tag that already exists ticks it rather than adding a twin', function () {
    Tag::query()->create(['name' => 'Winter', 'slug' => 'winter', 'status' => StatusDefault::ACTIVE]);

    $component = Livewire::actingAs($this->admin)
        ->test('pages::admin.content.post-edit')
        ->set('new_tag', 'winter')
        ->call('addTag');

    // Matched on the slug, so the library's spelling wins over what was typed.
    expect($component->get('tag_names'))->toBe(['Winter'])
        ->and($component->instance()->tagOptions)->toHaveCount(1);
});

test('a long tag library collapses behind a show more', function () {
    // Fifteen against the component's default limit of twelve.
    foreach (range(1, 15) as $number) {
        Tag::query()->create([
            'name' => "Tag {$number}",
            'slug' => "tag-{$number}",
            'status' => StatusDefault::ACTIVE,
        ]);
    }

    $this->actingAs($this->admin)
        ->get(route('admin.blog.create'))
        ->assertSuccessful()
        ->assertSee('Show 3 more');
});

test('the tags already on a post lead the picker, whatever the alphabet says', function () {
    $post = blogPost(['user_id' => $this->admin->id]);

    foreach (['Alpha', 'Winter'] as $name) {
        $tag = Tag::query()->create(['name' => $name, 'slug' => kSlug($name), 'status' => StatusDefault::ACTIVE]);

        if ($name === 'Winter') {
            $post->tags()->attach($tag);
        }
    }

    $component = Livewire::actingAs($this->admin)
        ->test('pages::admin.content.post-edit', ['post' => $post]);

    // "Alpha" sorts first in the library; "Winter" is on the post, so it leads.
    expect($component->instance()->tagOptions->all())->toBe(['Winter', 'Alpha']);
});

test('adding an empty tag does nothing', function () {
    $component = Livewire::actingAs($this->admin)
        ->test('pages::admin.content.post-edit')
        ->set('new_tag', '   ')
        ->call('addTag');

    expect($component->get('tag_names'))->toBeEmpty();
});

test('a category from another group cannot be posted onto a blog post', function () {
    $product = blogCategory('Gadgets', CategoryGroupEnum::PRODUCT);

    // The ids come from the browser, so the group is enforced in the rules rather
    // than trusted from the form.
    Livewire::actingAs($this->admin)
        ->test('pages::admin.content.post-edit')
        ->set('title', 'Wrong group')
        ->set('excerpt', 'Short.')
        ->set('content', '<p>Body.</p>')
        ->set('category_ids', [$product->id])
        ->set('status', StatusPost::DRAFT->value)
        ->call('save')
        ->assertHasErrors('category_ids.0');
});
