<?php

use App\Enums\ImageVisibilityEnum;
use App\Enums\UserRoleEnum;
use App\Models\Image;
use App\Models\ImageFolder;
use App\Models\Post;
use App\Services\ImageLibraryService;
use App\Services\SiteConfigurationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    // The library writes to the public disk; faking it keeps the suite from
    // leaving files in storage/app/public.
    Storage::fake('public');

    $this->member = userWithRole(UserRoleEnum::USER, ['email_verified_at' => now()]);
    $this->admin = userWithRole(UserRoleEnum::ADMIN, ['email_verified_at' => now()]);

    app(SiteConfigurationService::class)->update(initials: true);
});

function uploadedImage(string $name = 'photo.jpg'): UploadedFile
{
    return UploadedFile::fake()->image($name, 40, 30);
}

// ||||||||||||||||||||||||||||||||||||||||||||||||
// UPLOADING

test('an upload is stored on disk and recorded', function () {
    $image = app(ImageLibraryService::class)->store($this->member, uploadedImage());

    expect($image)->not->toBeNull()
        ->and($image->width)->toBe(40)
        ->and($image->height)->toBe(30)
        ->and($image->size)->toBeGreaterThan(0);

    Storage::disk('public')->assertExists($image->file_path);
});

test('the title falls back to the original file name', function () {
    $image = app(ImageLibraryService::class)->store($this->member, uploadedImage('holiday-banner.jpg'));

    expect($image->title)->toBe('holiday-banner');
});

test('several files upload at once', function () {
    $result = app(ImageLibraryService::class)->storeMany($this->member, [
        uploadedImage('one.jpg'),
        uploadedImage('two.jpg'),
        uploadedImage('three.jpg'),
    ]);

    expect($result['stored'])->toHaveCount(3)
        ->and($result['skipped'])->toBe(0);
});

test('a member cannot upload past their limit', function () {
    app(SiteConfigurationService::class)->update(['uploads' => ['user-image-limit' => 2]]);

    $result = app(ImageLibraryService::class)->storeMany($this->member, [
        uploadedImage('one.jpg'),
        uploadedImage('two.jpg'),
        uploadedImage('three.jpg'),
    ]);

    expect($result['stored'])->toHaveCount(2)
        ->and($result['skipped'])->toBe(1);
});

test('an administrator is not subject to the member upload limit', function () {
    app(SiteConfigurationService::class)->update(['uploads' => ['user-image-limit' => 1]]);

    expect(app(ImageLibraryService::class)->uploadLimitFor($this->admin))->toBeNull();

    $result = app(ImageLibraryService::class)->storeMany($this->admin, [
        uploadedImage('one.jpg'),
        uploadedImage('two.jpg'),
    ]);

    expect($result['stored'])->toHaveCount(2);
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// RENAMING

test('renaming changes the title and never the stored path', function () {
    $service = app(ImageLibraryService::class);

    $image = $service->store($this->member, uploadedImage());
    $originalPath = $image->file_path;

    $service->update($image, 'A better name');

    expect($image->fresh()->title)->toBe('A better name')
        // This is the whole point of splitting title from file_path: a rename must
        // not break a URL somebody already published.
        ->and($image->fresh()->file_path)->toBe($originalPath);

    Storage::disk('public')->assertExists($originalPath);
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// VISIBILITY

test('a private image is invisible to everybody but its owner and an admin', function () {
    $image = app(ImageLibraryService::class)->store($this->member, uploadedImage());

    $stranger = userWithRole(UserRoleEnum::USER, ['email_verified_at' => now()]);

    expect($image->isVisibleTo($this->member))->toBeTrue()
        ->and($image->isVisibleTo($this->admin))->toBeTrue()
        ->and($image->isVisibleTo($stranger))->toBeFalse()
        ->and($image->isVisibleTo(null))->toBeFalse();
});

test('a public image is visible to anybody', function () {
    $image = app(ImageLibraryService::class)->store(
        $this->member,
        uploadedImage(),
        visibility: ImageVisibilityEnum::PUBLIC
    );

    $stranger = userWithRole(UserRoleEnum::USER, ['email_verified_at' => now()]);

    expect($image->isVisibleTo($stranger))->toBeTrue()
        ->and($image->isVisibleTo(null))->toBeTrue();
});

test('a role image is visible only to accounts holding that role', function () {
    $image = app(ImageLibraryService::class)->store(
        $this->admin,
        uploadedImage(),
        visibility: ImageVisibilityEnum::ROLE,
        visibleToRole: UserRoleEnum::USER,
    );

    $member = userWithRole(UserRoleEnum::USER, ['email_verified_at' => now()]);

    expect($image->visible_to_role)->toBe(UserRoleEnum::USER)
        ->and($image->isVisibleTo($member))->toBeTrue();
});

test('leaving ROLE clears the role column so an old audience cannot come back', function () {
    $service = app(ImageLibraryService::class);

    $image = $service->store(
        $this->admin,
        uploadedImage(),
        visibility: ImageVisibilityEnum::ROLE,
        visibleToRole: UserRoleEnum::USER,
    );

    $service->update($image, $image->title, visibility: ImageVisibilityEnum::PRIVATE);

    expect($image->fresh()->visible_to_role)->toBeNull();
});

test('the library query hides other people images from a member', function () {
    $service = app(ImageLibraryService::class);

    $service->store($this->member, uploadedImage('mine.jpg'));
    $service->store($this->admin, uploadedImage('theirs.jpg'));

    expect($service->libraryQuery($this->member)->count())->toBe(1)
        // The admin's library is the site's media manager, so it shows everything.
        ->and($service->libraryQuery($this->admin)->count())->toBe(2);
});

test('search matches on the title', function () {
    $service = app(ImageLibraryService::class);

    $service->store($this->member, uploadedImage('summer-banner.jpg'));
    $service->store($this->member, uploadedImage('winter-logo.jpg'));

    expect($service->libraryQuery($this->member, search: 'banner')->count())->toBe(1);
});

test('the sort control reorders the grid', function () {
    $service = app(ImageLibraryService::class);

    $alpha = $service->store($this->member, uploadedImage('alpha.jpg'));
    $zulu = $service->store($this->member, uploadedImage('zulu.jpg'));

    expect($service->libraryQuery($this->member)->pluck('id')->all())->toBe([$zulu->id, $alpha->id])
        ->and($service->libraryQuery($this->member, sort: 'oldest')->pluck('id')->all())->toBe([$alpha->id, $zulu->id])
        ->and($service->libraryQuery($this->member, sort: 'name')->pluck('title')->all())->toBe(['alpha', 'zulu'])
        // An order nobody offers falls back to newest first, rather than leaving
        // the grid in whatever order the database felt like.
        ->and($service->libraryQuery($this->member, sort: 'nonsense')->pluck('id')->all())->toBe([$zulu->id, $alpha->id]);
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// FOLDERS

test('a folder groups images and deleting it leaves them behind', function () {
    $service = app(ImageLibraryService::class);

    $folder = $service->createFolder($this->member, 'Banners');
    $image = $service->store($this->member, uploadedImage(), folder: $folder);

    expect($service->libraryQuery($this->member, $folder)->count())->toBe(1);

    $service->deleteFolder($folder);

    // The image survives and falls back to the root — a tidy-up must not delete
    // content.
    expect($image->fresh())->not->toBeNull()
        ->and($image->fresh()->image_folder_id)->toBeNull();
});

test('deleting a parent folder promotes its children instead of taking the subtree', function () {
    $service = app(ImageLibraryService::class);

    $parent = $service->createFolder($this->member, 'Marketing');
    $child = $service->createFolder($this->member, 'Banners', $parent);

    $service->deleteFolder($parent);

    expect($child->fresh())->not->toBeNull()
        ->and($child->fresh()->parent_id)->toBeNull();
});

test('two people may each have a folder of the same name', function () {
    $service = app(ImageLibraryService::class);

    $service->createFolder($this->member, 'Banners');
    $service->createFolder($this->admin, 'Banners');

    expect(ImageFolder::query()->where('slug', 'banners')->count())->toBe(2);
});

test('a member only sees their own folders plus the shared ones', function () {
    $service = app(ImageLibraryService::class);

    $service->createFolder($this->member, 'Mine');
    $service->createFolder($this->admin, 'Theirs');
    $service->createFolder($this->admin, 'Everyone', shared: true);

    $labels = $service->folderOptions($this->member)->pluck('label');

    expect($labels)->toContain('Mine')
        ->and($labels)->toContain('Everyone')
        ->and($labels)->not->toContain('Theirs');
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// DELETING

test('an unattached image deletes along with its file', function () {
    $service = app(ImageLibraryService::class);

    $image = $service->store($this->member, uploadedImage());
    $path = $image->file_path;

    expect($service->delete($image))->toBeNull();

    Storage::disk('public')->assertMissing($path);
    expect(Image::query()->count())->toBe(0);
});

test('an attached image refuses to be deleted', function () {
    $service = app(ImageLibraryService::class);

    $image = $service->store($this->member, uploadedImage());

    $post = Post::query()->create([
        'user_id' => $this->admin->id,
        'title' => 'Something',
        'slug' => 'something',
        'excerpt' => 'A short line.',
        'content' => '<p>Body.</p>',
    ]);

    $service->attach($image, $post, 'cover');

    expect($service->delete($image))->not->toBeNull()
        ->and(Image::query()->count())->toBe(1);

    // And the file is still there — a refused delete must not half-happen.
    Storage::disk('public')->assertExists($image->file_path);
});

test('attaching the same image twice does not stack usage rows', function () {
    $service = app(ImageLibraryService::class);

    $image = $service->store($this->member, uploadedImage());

    $post = Post::query()->create([
        'user_id' => $this->admin->id,
        'title' => 'Something',
        'slug' => 'something',
        'excerpt' => 'A short line.',
        'content' => '<p>Body.</p>',
    ]);

    $service->attach($image, $post, 'cover');
    $service->attach($image, $post, 'cover');

    expect($image->usages()->count())->toBe(1);
});

test('detaching frees an image for deletion again', function () {
    $service = app(ImageLibraryService::class);

    $image = $service->store($this->member, uploadedImage());

    $post = Post::query()->create([
        'user_id' => $this->admin->id,
        'title' => 'Something',
        'slug' => 'something',
        'excerpt' => 'A short line.',
        'content' => '<p>Body.</p>',
    ]);

    $service->attach($image, $post, 'cover');
    $service->detach($post, 'cover');

    expect($service->delete($image->fresh()))->toBeNull();
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// THE SCREEN

test('both workspaces can open the library', function () {
    $this->actingAs($this->member)->get(route('user.image-library'))->assertSuccessful();
    $this->actingAs($this->admin)->get(route('admin.image-library'))->assertSuccessful();
});

test('uploading through the screen works', function () {
    Livewire::actingAs($this->member)
        ->test('pages::shared.image-library')
        ->set('imagesUpload', [uploadedImage()])
        ->call('uploadImages')
        ->assertHasNoErrors();

    expect($this->member->images()->count())->toBe(1);
});

test('the screen sorts on request', function () {
    $service = app(ImageLibraryService::class);

    $alpha = $service->store($this->member, uploadedImage('alpha.jpg'));
    $zulu = $service->store($this->member, uploadedImage('zulu.jpg'));

    Livewire::actingAs($this->member)
        ->test('pages::shared.image-library')
        ->set('sort', 'name')
        ->assertSeeInOrder([$alpha->title, $zulu->title]);
});

test('the picker uploads without leaving the page it sits on', function () {
    $component = Livewire::actingAs($this->member)
        ->test('lv.image-picker')
        ->call('open')
        ->set('imagesUpload', [uploadedImage('from-the-picker.jpg')])
        ->call('uploadImages')
        ->assertHasNoErrors();

    // The upload has to land in the grid straight away — the whole point of the
    // picker is not having to leave the post you are writing.
    $component->assertSee('from-the-picker');

    expect($this->member->images()->count())->toBe(1);
});

test('no upload action is named after a $wire alias', function () {
    // Livewire maps a handful of bare names on the $wire object onto its own
    // helpers, so a component method sharing one of those names is silently
    // unreachable from Blade: wire:submit="upload" calls Livewire's $upload()
    // and throws in the browser before a request is ever sent.
    $reserved = ['upload', 'set', 'get', 'call', 'dispatch', 'commit', 'errors', 'island', 'entangle'];

    $components = [
        'resources/views/pages/shared/⚡image-library.blade.php',
        'resources/views/components/lv/⚡image-picker.blade.php',
    ];

    foreach ($components as $component) {
        // Comments in these files name the trap on purpose, so they are stripped
        // before the source is searched for anybody actually falling into it.
        $source = (string) preg_replace(
            ['#/\*.*?\*/#s', '#\{\{--.*?--\}\}#s', '#//[^\n]*#'],
            '',
            file_get_contents(base_path($component))
        );

        foreach ($reserved as $name) {
            expect($source)->not->toContain("public function {$name}(")
                ->and($source)->not->toMatch('/wire:(submit|click)="'.$name.'(\(|")/');
        }
    }
});

test('a member cannot edit somebody else image through the screen', function () {
    $image = app(ImageLibraryService::class)->store($this->admin, uploadedImage());

    Livewire::actingAs($this->member)
        ->test('pages::shared.image-library')
        ->call('edit', $image->id)
        ->assertStatus(404);
});
