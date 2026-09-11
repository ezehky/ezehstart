<?php

use App\Enums\CategoryGroupEnum;
use App\Enums\StatusDefault;
use App\Enums\UserTypeEnum;
use App\Models\Category;
use App\Models\Image;
use App\Models\Post;
use App\Services\SpreadsheetService;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * The categories screen after the table kit: importing a vocabulary, exporting one,
 * and the guard that keeps a category with posts under it from being cleared out in a
 * bulk selection.
 */
beforeEach(function () {
    $this->admin = userOfType(UserTypeEnum::ADMIN);
});

/**
 * @return Testable
 */
function categoryScreen()
{
    return Livewire::actingAs(test()->admin)
        ->test('pages::admin.content.categories', ['category_group' => CategoryGroupEnum::BLOG]);
}

function blogVocabulary(string $name, array $attributes = []): Category
{
    return Category::query()->create([
        'category_group' => CategoryGroupEnum::BLOG,
        'name' => $name,
        'slug' => kSlug($name.' blog'),
        'status' => StatusDefault::ACTIVE,
        ...$attributes,
    ]);
}

// |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
// IMPORT

test('a file of names becomes a vocabulary', function () {
    categoryScreen()
        ->set('importFile', csvUpload("name,description,order\nGuides,How to do things,2\nNews,,1\n"))
        ->call('import')
        ->assertHasNoErrors();

    $guides = Category::query()->where('name', 'Guides')->first();

    expect(Category::query()->count())->toBe(2)
        ->and($guides->description)->toBe('How to do things')
        ->and($guides->flow_order)->toBe(2)
        ->and($guides->category_group)->toBe(CategoryGroupEnum::BLOG);
});

test('a parent named in the file is matched inside the same group', function () {
    blogVocabulary('Guides');

    categoryScreen()
        ->set('importFile', csvUpload("name,parent\nBeginner guides,Guides\n"))
        ->call('import');

    expect(Category::query()->where('name', 'Beginner guides')->first()->parentName())->toBe('Guides');
});

test('a parent the file names but the group does not have leaves the row at the top level', function () {
    categoryScreen()
        ->set('importFile', csvUpload("name,parent\nGuides,Nothing By That Name\n"))
        ->call('import')
        ->assertHasNoErrors();

    expect(Category::query()->where('name', 'Guides')->first()->parent_id)->toBeNull();
});

test('a name already in the group is left where it is', function () {
    blogVocabulary('Guides', ['description' => 'The original wording.']);

    $component = categoryScreen()
        ->set('importFile', csvUpload("name,description\nGuides,Something else\n"))
        ->call('import');

    expect(Category::query()->count())->toBe(1)
        ->and(Category::query()->first()->description)->toBe('The original wording.')
        ->and($component->get('importedCount'))->toBe(0);
});

// |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
// EXPORT

test('an export carries the parent as its name rather than its id', function () {
    $parent = blogVocabulary('Guides');
    blogVocabulary('Beginner guides', ['parent_id' => $parent->id]);

    $component = categoryScreen();
    $component->call('export', 'csv');

    $path = tempnam(sys_get_temp_dir(), 'categories').'.csv';
    file_put_contents($path, base64_decode($component->effects['download']['content']));

    $rows = app(SpreadsheetService::class)->rows($path, 'csv');

    expect($rows)->toHaveCount(2)
        ->and(collect($rows)->firstWhere('name', 'Beginner guides')['parent'])->toBe('Guides');
});

test('the columns that only make sense on screen stay out of the file', function () {
    blogVocabulary('Guides');

    // The image is a picture and the attached count is a live figure; neither is
    // something a spreadsheet can carry.
    expect(array_keys(categoryScreen()->get('tableExportHeaders')))
        ->not->toContain('image')
        ->not->toContain('attached_count');
});

// |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
// THE DELETE GUARD

test('a category with posts under it survives a bulk delete', function () {
    $used = blogVocabulary('Guides');
    $free = blogVocabulary('News');

    $post = Post::query()->create([
        'title' => 'A post', 'slug' => 'a-post', 'excerpt' => 'x', 'content' => '<p>x</p>',
    ]);
    $post->categories()->attach($used);

    categoryScreen()
        ->set('selected', [(string) $used->id, (string) $free->id])
        ->call('bulkDelete')
        ->assertHasNoErrors();

    // The one nothing is filed under goes; the one with a post under it stays.
    expect(Category::query()->pluck('name')->all())->toBe(['Guides']);
});

test('a bulk delete where every row is refused says so rather than claiming success', function () {
    $used = blogVocabulary('Guides');

    $post = Post::query()->create([
        'title' => 'A post', 'slug' => 'a-post', 'excerpt' => 'x', 'content' => '<p>x</p>',
    ]);
    $post->categories()->attach($used);

    categoryScreen()
        ->set('selected', [(string) $used->id])
        ->call('bulkDelete')
        ->assertHasErrors();

    expect(Category::query()->count())->toBe(1);
});

// |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
// THE IMAGE

test('a category carries a cover image, and the row shows it', function () {
    $image = Image::query()->create([
        'user_id' => $this->admin->id,
        'title' => 'Cover',
        'file_path' => 'images/cover.jpg',
        'disk' => 'public',
        'mime_type' => 'image/jpeg',
        'extension' => 'jpg',
        'size' => 1024,
    ]);

    $category = blogVocabulary('Guides');

    $component = categoryScreen()->call('edit', $category->id);

    $component->call('whenImagesSelected', [$image->id], [], 'cover')->call('save');

    expect($category->fresh()->image_id)->toBe($image->id);

    categoryScreen()->assertSee($image->url(), escape: false);
});
