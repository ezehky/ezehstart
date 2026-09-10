<?php

use App\Enums\MediaVisibilityEnum;
use App\Enums\UserTypeEnum;
use App\Models\Image;
use App\Models\ImageFolder;
use App\Models\Post;
use App\Models\User;
use App\Services\ImageLibraryService;
use App\Services\SiteConfigurationService;
use App\Traits\WithFormResponseMessage;
use App\Traits\WithImagePicker;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\Livewire;

beforeEach(function () {
    // The library writes to the public disk; faking it keeps the suite from
    // leaving files in storage/app/public.
    Storage::fake('public');

    $this->member = userOfType(UserTypeEnum::USER, ['email_verified_at' => now()]);
    $this->admin = userOfType(UserTypeEnum::ADMIN, ['email_verified_at' => now()]);

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

    $stranger = userOfType(UserTypeEnum::USER, ['email_verified_at' => now()]);

    expect($image->isVisibleTo($this->member))->toBeTrue()
        ->and($image->isVisibleTo($this->admin))->toBeTrue()
        ->and($image->isVisibleTo($stranger))->toBeFalse()
        ->and($image->isVisibleTo(null))->toBeFalse();
});

test('a public image is visible to anybody', function () {
    $image = app(ImageLibraryService::class)->store(
        $this->member,
        uploadedImage(),
        visibility: MediaVisibilityEnum::PUBLIC
    );

    $stranger = userOfType(UserTypeEnum::USER, ['email_verified_at' => now()]);

    expect($image->isVisibleTo($stranger))->toBeTrue()
        ->and($image->isVisibleTo(null))->toBeTrue();
});

test('a type image is visible only to accounts of that type', function () {
    $image = app(ImageLibraryService::class)->store(
        $this->admin,
        uploadedImage(),
        visibility: MediaVisibilityEnum::TYPE,
        visibleToType: UserTypeEnum::USER,
    );

    $member = userOfType(UserTypeEnum::USER, ['email_verified_at' => now()]);

    expect($image->visible_to_type)->toBe(UserTypeEnum::USER)
        ->and($image->isVisibleTo($member))->toBeTrue();
});

test('leaving TYPE clears the type column so an old audience cannot come back', function () {
    $service = app(ImageLibraryService::class);

    $image = $service->store(
        $this->admin,
        uploadedImage(),
        visibility: MediaVisibilityEnum::TYPE,
        visibleToType: UserTypeEnum::USER,
    );

    $service->update($image, $image->title, visibility: MediaVisibilityEnum::PRIVATE);

    expect($image->fresh()->visible_to_type)->toBeNull();
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
// FOLDER VISIBILITY

test('a private folder stays out of another member folder rail', function () {
    $service = app(ImageLibraryService::class);
    $other = userOfType(UserTypeEnum::USER, ['email_verified_at' => now()]);

    $service->createFolder($other, 'Theirs');
    $service->createFolder($other, 'Open to all', visibility: MediaVisibilityEnum::PUBLIC);

    $labels = $service->folderOptions($this->member)->pluck('label');

    expect($labels)->toContain('Open to all')
        ->and($labels)->not->toContain('Theirs');
});

test('a role folder reaches only the role it names', function () {
    $service = app(ImageLibraryService::class);

    $service->createFolder(
        $this->admin,
        'Staff only',
        visibility: MediaVisibilityEnum::TYPE,
        visibleToType: UserTypeEnum::ADMIN,
    );

    expect($service->folderOptions($this->member)->pluck('label'))->not->toContain('Staff only')
        // The admin sees everything anyway: the library is the site's media manager.
        ->and($service->folderOptions($this->admin)->pluck('label'))->toContain('Staff only');
});

test('tightening a folder does not move the images inside it', function () {
    $service = app(ImageLibraryService::class);

    $folder = $service->createFolder($this->member, 'Mixed', visibility: MediaVisibilityEnum::PUBLIC);
    $image = $service->store($this->member, uploadedImage(), folder: $folder, visibility: MediaVisibilityEnum::PUBLIC);

    $service->updateFolder($folder, 'Mixed', MediaVisibilityEnum::PRIVATE);

    // The folder closed; the image kept the audience it was given. Permissions
    // that move when a file is refiled are permissions nobody can reason about.
    expect($image->fresh()->visibility)->toBe(MediaVisibilityEnum::PUBLIC);
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// MOVING

test('images move between folders and back to the root', function () {
    $service = app(ImageLibraryService::class);

    $folder = $service->createFolder($this->member, 'Banners');
    $images = collect([
        $service->store($this->member, uploadedImage('one.jpg')),
        $service->store($this->member, uploadedImage('two.jpg')),
    ]);

    expect($service->moveImages($images, $folder))->toBe(2)
        ->and($images->first()->fresh()->image_folder_id)->toBe($folder->id);

    $service->moveImages($images, null);

    expect($images->first()->fresh()->image_folder_id)->toBeNull();
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// THE PICKER

test('the picker picks one image and hands it straight back', function () {
    $image = app(ImageLibraryService::class)->store($this->member, uploadedImage());

    Livewire::actingAs($this->member)
        ->test('lv.image-picker')
        ->call('open')
        ->call('toggle', $image->id)
        // The single-pick contract the blog editor already depends on.
        ->assertDispatched('imageSelected', imageId: $image->id)
        ->assertDispatched('image-picked')
        ->assertSet('show', false);
});

test('the picker holds several images until the choice is confirmed', function () {
    $service = app(ImageLibraryService::class);
    $one = $service->store($this->member, uploadedImage('one.jpg'));
    $two = $service->store($this->member, uploadedImage('two.jpg'));

    $picker = Livewire::actingAs($this->member)
        ->test('lv.image-picker')
        ->call('open', true)
        ->call('toggle', $one->id)
        ->call('toggle', $two->id)
        // Nothing is handed back while the person is still choosing.
        ->assertSet('show', true);

    expect($picker->get('selected'))->toHaveCount(2);

    $picker->call('toggle', $one->id);
    expect($picker->get('selected'))->toHaveCount(1);

    $picker->call('toggle', $one->id)->call('confirmSelection')->assertDispatched('imagesSelected');
});

test('a multiple selection stops at the ceiling the caller set', function () {
    $service = app(ImageLibraryService::class);
    $one = $service->store($this->member, uploadedImage('one.jpg'));
    $two = $service->store($this->member, uploadedImage('two.jpg'));

    Livewire::actingAs($this->member)
        ->test('lv.image-picker')
        ->call('open', true, 1)
        ->call('toggle', $one->id)
        ->call('toggle', $two->id)
        ->assertHasErrors();
});

test('the picker refuses to delete an image that is still in use', function () {
    $service = app(ImageLibraryService::class);
    $image = $service->store($this->member, uploadedImage('busy.jpg'));

    $post = Post::query()->create([
        'user_id' => $this->member->id,
        'title' => 'Something',
        'slug' => 'something',
        'excerpt' => 'A short line.',
        'content' => '<p>Body.</p>',
    ]);

    $service->attach($image, $post, 'cover');

    Livewire::actingAs($this->member)
        ->test('lv.image-picker')
        ->call('open')
        ->set('selected', [$image->id])
        ->call('deleteSelected');

    // Refused, not deleted — the row and the file both survive.
    expect(Image::query()->whereKey($image->id)->exists())->toBeTrue();
});

test('the picker will not let a member delete somebody else image', function () {
    $image = app(ImageLibraryService::class)->store($this->admin, uploadedImage(), visibility: MediaVisibilityEnum::PUBLIC);

    Livewire::actingAs($this->member)
        ->test('lv.image-picker')
        ->call('open')
        // Visible to them, but not theirs to manage, so it never reaches the batch.
        ->set('selected', [$image->id])
        ->call('deleteSelected')
        ->assertHasErrors();

    expect(Image::query()->whereKey($image->id)->exists())->toBeTrue();
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// THE UPLOADER

test('the uploader stages files before it stores any of them', function () {
    $uploader = Livewire::actingAs($this->member)
        ->test('lv.image-uploader')
        ->set('imagesUpload', [uploadedImage('one.jpg'), uploadedImage('two.jpg')]);

    // Staged, not stored: somebody who dragged in a wrong file can still drop it.
    expect($this->member->images()->count())->toBe(0);

    $uploader->call('removeStaged', 0);
    expect($uploader->get('imagesUpload'))->toHaveCount(1);

    $uploader->call('uploadImages')->assertHasNoErrors()->assertDispatched('imagesUploaded');
    expect($this->member->images()->count())->toBe(1);
});

test('an upload takes the visibility of the folder it lands in', function () {
    $folder = app(ImageLibraryService::class)->createFolder(
        $this->member,
        'Public shelf',
        visibility: MediaVisibilityEnum::PUBLIC,
    );

    Livewire::actingAs($this->member)
        ->test('lv.image-uploader', ['folder' => $folder->id])
        ->set('imagesUpload', [uploadedImage()])
        ->call('uploadImages')
        ->assertHasNoErrors();

    // The folder is where a new image starts. It keeps that setting afterwards
    // wherever it is refiled.
    expect($this->member->images()->first()->visibility)->toBe(MediaVisibilityEnum::PUBLIC);
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// THE SCREEN

test('both workspaces can open the library', function () {
    $this->actingAs($this->member)->get(route('user.image-library'))->assertSuccessful();
    $this->actingAs($this->admin)->get(route('admin.image-library'))->assertSuccessful();
});

test('the page picks up what the uploader stored', function () {
    $image = app(ImageLibraryService::class)->store($this->member, uploadedImage('fresh.jpg'));

    $page = Livewire::actingAs($this->member)
        ->test('pages::shared.image-library')
        ->dispatch('imagesUploaded', ids: [$image->id]);

    // Same handoff as the picker: uploading lands you back on the grid with the
    // new image ticked.
    expect($page->get('selected'))->toBe([$image->id]);

    $page->assertSet('tab', 'library')->assertSee('fresh');
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

test('what the uploader stores comes back selected in the picker', function () {
    $image = app(ImageLibraryService::class)->store($this->member, uploadedImage('fresh.jpg'));

    $picker = Livewire::actingAs($this->member)
        ->test('lv.image-picker')
        ->call('open', true)
        // The uploader announces what it stored; the picker is listening.
        ->dispatch('imagesUploaded', ids: [$image->id]);

    // Back on the grid with the new upload already ticked — the whole point of
    // uploading from inside a picker is not having to go and find it again.
    expect($picker->get('selected'))->toBe([$image->id]);

    $picker->assertSet('tab', 'library')->assertSee('fresh');
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
        'resources/views/components/lv/⚡image-uploader.blade.php',
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

test('a member cannot edit somebody else image on either screen', function () {
    $image = app(ImageLibraryService::class)->store(
        $this->admin,
        uploadedImage(),
        visibility: MediaVisibilityEnum::PUBLIC,
    );

    // Visible to them, but not theirs to change, so the panel refuses to open.
    foreach (['pages::shared.image-library', 'lv.image-picker'] as $screen) {
        Livewire::actingAs($this->member)
            ->test($screen)
            ->set('selected', [$image->id])
            ->call('openPanel', 'edit')
            ->assertHasErrors();
    }
});

test('the page and the picker offer the same library actions', function () {
    // These two drifted apart once. Everything they both do now lives in
    // WithImageLibrary, and this is what stops one of them growing a feature the
    // other does not have.
    $actions = [
        'selectFolder', 'switchTab', 'toggle', 'clearSelection',
        'openPanel', 'closePanel', 'saveImage', 'moveSelected', 'deleteSelected',
        'newFolder', 'editFolder', 'saveFolder', 'deleteFolder', 'afterUpload',
    ];

    $state = ['search', 'folder', 'sort', 'selected', 'multiple', 'max', 'tab', 'panel'];

    $page = Livewire::actingAs($this->member)->test('pages::shared.image-library')->instance();
    $picker = Livewire::actingAs($this->member)->test('lv.image-picker')->instance();

    foreach ($actions as $action) {
        expect(method_exists($page, $action))->toBeTrue("the page is missing {$action}()")
            ->and(method_exists($picker, $action))->toBeTrue("the picker is missing {$action}()");
    }

    foreach ($state as $property) {
        expect(property_exists($page, $property))->toBeTrue("the page is missing {$property}")
            ->and(property_exists($picker, $property))->toBeTrue("the picker is missing {$property}");
    }
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// SLOTS

/**
 * A stand-in for any screen that holds images: one slot backed by a column, one
 * that takes several and lives only in image_usages. The real screens hold one
 * or two, so this is where the combinations WithImagePicker promises are put
 * through their paces.
 */
class SlotHolder extends Component
{
    use WithFormResponseMessage;
    use WithImagePicker;

    public ?int $image_id = null;

    public ?int $record_id = null;

    protected function imageSlots(): array
    {
        return [
            'cover' => ['multiple' => false, 'property' => 'image_id'],
            'gallery' => ['multiple' => true, 'max' => 3],
        ];
    }

    public function loadFrom(): void
    {
        $this->loadImageSlots(Post::query()->findOrFail($this->record_id));
    }

    public function persist(): void
    {
        $this->syncImageSlots(Post::query()->findOrFail($this->record_id));
    }

    public function render()
    {
        return '<div></div>';
    }
}

function slotPost(User $user): Post
{
    return Post::query()->create([
        'user_id' => $user->id,
        'title' => 'Holder '.uniqid(),
        'slug' => 'holder-'.uniqid(),
        'excerpt' => 'A short line.',
        'content' => '<p>Body.</p>',
    ]);
}

test('a slot takes only the picks addressed to it', function () {
    $service = app(ImageLibraryService::class);
    $cover = $service->store($this->member, uploadedImage('cover.jpg'));
    $other = $service->store($this->member, uploadedImage('other.jpg'));

    $holder = Livewire::actingAs($this->member)
        ->test(SlotHolder::class)
        ->dispatch('imagesSelected', ids: [$cover->id], urls: [], slot: 'cover');

    // The column-backed slot keeps its column in step, so the page's own fill(),
    // rules() and save() go on reading the property they always did.
    expect($holder->get('image_id'))->toBe($cover->id);

    // A pick for the other slot, and one for a slot this screen never declared.
    $holder->dispatch('imagesSelected', ids: [$other->id], urls: [], slot: 'gallery')
        ->dispatch('imagesSelected', ids: [$other->id], urls: [], slot: 'nonsense');

    expect($holder->get('image_slots')['cover'])->toBe([$cover->id])
        ->and($holder->get('image_slots')['gallery'])->toBe([$other->id])
        ->and($holder->get('image_id'))->toBe($cover->id);
});

test('a single slot never holds more than one and a multiple slot stops at its max', function () {
    $service = app(ImageLibraryService::class);
    $images = collect(range(1, 4))->map(fn (int $n) => $service->store($this->member, uploadedImage("shot-{$n}.jpg")));

    $holder = Livewire::actingAs($this->member)
        ->test(SlotHolder::class)
        // The ids arrive over the wire, so the slot has to be the thing that
        // refuses four rather than trusting the picker to have enforced it.
        ->dispatch('imagesSelected', ids: $images->pluck('id')->all(), urls: [], slot: 'cover')
        ->dispatch('imagesSelected', ids: $images->pluck('id')->all(), urls: [], slot: 'gallery');

    expect($holder->get('image_slots')['cover'])->toBe([$images[0]->id])
        ->and($holder->get('image_slots')['gallery'])->toHaveCount(3);
});

test('a slot writes usage rows and reads them back in the order they were chosen', function () {
    $service = app(ImageLibraryService::class);
    $one = $service->store($this->member, uploadedImage('one.jpg'));
    $two = $service->store($this->member, uploadedImage('two.jpg'));
    $three = $service->store($this->member, uploadedImage('three.jpg'));

    $post = slotPost($this->member);

    Livewire::actingAs($this->member)
        ->test(SlotHolder::class, ['record_id' => $post->id])
        ->dispatch('imagesSelected', ids: [$three->id, $one->id, $two->id], urls: [], slot: 'gallery')
        ->call('persist');

    expect($service->usedImageIds($post, 'gallery'))->toBe([$three->id, $one->id, $two->id]);

    // A record opened again shows what it holds, in the order it was arranged.
    $reopened = Livewire::actingAs($this->member)
        ->test(SlotHolder::class, ['record_id' => $post->id])
        ->call('loadFrom');

    expect($reopened->get('image_slots')['gallery'])->toBe([$three->id, $one->id, $two->id]);
});

test('a column-backed slot falls back to its column when no usage row was ever written', function () {
    $image = app(ImageLibraryService::class)->store($this->member, uploadedImage());

    $post = slotPost($this->member);

    // A record written before the slot existed: the column holds the image and
    // nothing ever recorded the usage.
    $post->update(['image_id' => $image->id]);

    $holder = Livewire::actingAs($this->member)
        ->test(SlotHolder::class, ['record_id' => $post->id, 'image_id' => $image->id])
        ->call('loadFrom');

    expect($holder->get('image_slots')['cover'])->toBe([$image->id]);

    // And saving now records it, so the delete guard can finally see it.
    $holder->call('persist');

    expect($image->fresh()->isAttached())->toBeTrue();
});

test('emptying a slot releases the image', function () {
    $image = app(ImageLibraryService::class)->store($this->member, uploadedImage());
    $post = slotPost($this->member);

    $holder = Livewire::actingAs($this->member)
        ->test(SlotHolder::class, ['record_id' => $post->id])
        ->dispatch('imagesSelected', ids: [$image->id], urls: [], slot: 'cover')
        ->call('persist');

    expect($image->fresh()->isAttached())->toBeTrue();

    $holder->call('clearImages', 'cover')->call('persist');

    expect($image->fresh()->isAttached())->toBeFalse()
        ->and($holder->get('image_id'))->toBeNull();
});

test('removing one image from a slot leaves the rest attached', function () {
    $service = app(ImageLibraryService::class);
    $one = $service->store($this->member, uploadedImage('one.jpg'));
    $two = $service->store($this->member, uploadedImage('two.jpg'));
    $post = slotPost($this->member);

    Livewire::actingAs($this->member)
        ->test(SlotHolder::class, ['record_id' => $post->id])
        ->dispatch('imagesSelected', ids: [$one->id, $two->id], urls: [], slot: 'gallery')
        ->call('removeImage', 'gallery', $one->id)
        ->call('persist');

    expect($service->usedImageIds($post, 'gallery'))->toBe([$two->id])
        ->and($one->fresh()->isAttached())->toBeFalse()
        ->and($two->fresh()->isAttached())->toBeTrue();
});

test('one slot on a record does not disturb another', function () {
    $service = app(ImageLibraryService::class);
    $cover = $service->store($this->member, uploadedImage('cover.jpg'));
    $shot = $service->store($this->member, uploadedImage('shot.jpg'));
    $post = slotPost($this->member);

    Livewire::actingAs($this->member)
        ->test(SlotHolder::class, ['record_id' => $post->id])
        ->dispatch('imagesSelected', ids: [$cover->id], urls: [], slot: 'cover')
        ->dispatch('imagesSelected', ids: [$shot->id], urls: [], slot: 'gallery')
        ->call('persist');

    // Two fields on one record are two independent claims on the library.
    expect($service->usedImageIds($post, 'cover'))->toBe([$cover->id])
        ->and($service->usedImageIds($post, 'gallery'))->toBe([$shot->id]);
});

test('an undeclared slot is refused rather than created', function () {
    // The slot name arrives from the browser on every click.
    Livewire::actingAs($this->member)
        ->test(SlotHolder::class)
        ->call('chooseImage', 'not-a-slot')
        ->assertStatus(404);
});

test('the picker carries the slot that asked and leaves the editor alone', function () {
    $image = app(ImageLibraryService::class)->store($this->member, uploadedImage());

    Livewire::actingAs($this->member)
        ->test('lv.image-picker')
        ->call('open', false, null, 'cover')
        ->call('toggle', $image->id)
        ->assertDispatched('imagesSelected', ids: [$image->id], slot: 'cover')
        // A cover chosen for a form must not also drop itself into the body being
        // written: the tiptap editor's event only fires when nobody named a slot.
        ->assertNotDispatched('image-picked');
});

test('reopening a multiple slot shows what it already holds', function () {
    $service = app(ImageLibraryService::class);
    $one = $service->store($this->member, uploadedImage('one.jpg'));
    $two = $service->store($this->member, uploadedImage('two.jpg'));

    Livewire::actingAs($this->member)
        ->test('lv.image-picker')
        // Without this, confirming would replace the pair rather than add to it.
        ->call('open', true, 3, 'gallery', [$one->id, $two->id])
        ->assertSet('selected', [$one->id, $two->id]);
});

test('the blog editor holds its cover through the slot', function () {
    $image = app(ImageLibraryService::class)->store($this->admin, uploadedImage('cover.jpg'));

    Livewire::actingAs($this->admin)
        ->test('pages::admin.content.post-edit')
        ->set('title', 'A post with a cover')
        ->set('excerpt', 'A short line.')
        ->set('content', '<p>Body.</p>')
        ->assertSee('Choose from library')
        ->dispatch('imagesSelected', ids: [$image->id], urls: [], slot: 'cover')
        // The slot control draws what it holds and offers the way out of it.
        ->assertSee($image->url())
        ->assertSee('Replace')
        ->call('save')
        ->assertHasNoErrors();

    $post = Post::query()->where('title', 'A post with a cover')->first();

    expect($post->image_id)->toBe($image->id)
        // The usage row is what stops the file being deleted out from under it.
        ->and(app(ImageLibraryService::class)->usedImageIds($post, 'cover'))->toBe([$image->id]);
});
