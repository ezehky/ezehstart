<?php

use App\Enums\ActivityActionEnum;
use App\Enums\UserTypeEnum;
use App\Models\ActivityLog;
use App\Services\CloudStorageService;
use App\Services\ImageLibraryService;
use App\Services\SiteConfigurationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('public');
    Storage::fake('r2');

    $this->admin = userOfType(UserTypeEnum::ADMIN, ['email_verified_at' => now()]);
    $this->member = userOfType(UserTypeEnum::USER, ['email_verified_at' => now()]);

    app(SiteConfigurationService::class)->update(initials: true);
});

/**
 * What a real install puts in .env. The fake disk ignores them; the service only
 * asks that they are there.
 */
function withR2Keys(): void
{
    config([
        'filesystems.disks.r2.key' => 'key',
        'filesystems.disks.r2.secret' => 'secret',
        'filesystems.disks.r2.bucket' => 'media',
        'filesystems.disks.r2.endpoint' => 'https://account.r2.cloudflarestorage.com',
        'filesystems.disks.r2.url' => 'https://media.example.com',
    ]);
}

function switchR2(bool $on): void
{
    $service = app(SiteConfigurationService::class);
    $service->update([...$service->getConfigs(raw: true), 'storage' => ['cloud' => $on, 'public-url' => null]]);
}

test('uploads stay on this server by default', function () {
    withR2Keys();

    $image = app(ImageLibraryService::class)->store($this->member, UploadedFile::fake()->image('photo.jpg', 40, 30));

    expect($image->disk)->toBe(CloudStorageService::LOCAL_DISK);
    Storage::disk('public')->assertExists($image->file_path);
});

test('a switch turned on over missing keys still keeps uploads here', function () {
    switchR2(true);

    $storage = app(CloudStorageService::class);

    expect($storage->isEnabled())->toBeFalse()
        ->and($storage->missingEnv())->toBe(['R2_ACCESS_KEY_ID', 'R2_SECRET_ACCESS_KEY', 'R2_BUCKET', 'R2_ENDPOINT'])
        ->and($storage->disk())->toBe(CloudStorageService::LOCAL_DISK);
});

test('with the switch on and the keys set, an upload goes to R2', function () {
    withR2Keys();
    switchR2(true);

    $image = app(ImageLibraryService::class)->store($this->member, UploadedFile::fake()->image('photo.jpg', 40, 30));

    expect($image->disk)->toBe(CloudStorageService::CLOUD_DISK)
        ->and($image->width)->toBe(40)
        ->and($image->size)->toBeGreaterThan(0)
        ->and($image->url())->toBe(Storage::disk('r2')->url($image->file_path));

    Storage::disk('r2')->assertExists($image->file_path);
    Storage::disk('public')->assertMissing($image->file_path);
});

test('an image is deleted from the disk its row names', function () {
    withR2Keys();
    switchR2(true);

    $image = app(ImageLibraryService::class)->store($this->member, UploadedFile::fake()->image('photo.jpg'));
    $path = $image->file_path;

    // Switched off afterwards: the file is still on R2, and that is where it goes from.
    switchR2(false);

    expect(app(ImageLibraryService::class)->delete($image))->toBeNull();

    Storage::disk('r2')->assertMissing($path);
});

test('an image on R2 is cropped and sent back over itself', function () {
    withR2Keys();
    switchR2(true);
    app(SiteConfigurationService::class)->update([
        ...app(SiteConfigurationService::class)->getConfigs(raw: true),
        'uploads' => ['convert-to-webp' => false],
    ]);

    $image = app(ImageLibraryService::class)->store($this->member, UploadedFile::fake()->image('wide.png', 400, 200));
    $path = $image->file_path;

    expect(app(ImageLibraryService::class)->crop($image, 0.25, 0, 0.5, 1))->not->toBeNull();

    $image->refresh();

    expect($image->file_path)->toBe($path)
        ->and($image->disk)->toBe(CloudStorageService::CLOUD_DISK)
        ->and($image->width)->toBe(200);

    Storage::disk('r2')->assertExists($path);
    // The working copy does not stay behind on this server.
    Storage::disk('public')->assertMissing($path);
    expect(getimagesizefromstring(Storage::disk('r2')->get($path))[0])->toBe(200);
});

test('a sync moves existing uploads onto R2 and logs once', function () {
    $images = collect(range(1, 3))->map(fn (int $n) => app(ImageLibraryService::class)
        ->store($this->member, UploadedFile::fake()->image("photo-{$n}.jpg")));

    withR2Keys();
    switchR2(true);

    expect(app(CloudStorageService::class)->pendingCount())->toBe(3);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.configs.file-storage')
        ->call('startSync')
        ->assertSet('syncing', false)
        ->assertSet('syncMoved', 3);

    $images->each(function ($image) {
        $image->refresh();

        expect($image->disk)->toBe(CloudStorageService::CLOUD_DISK);
        Storage::disk('r2')->assertExists($image->file_path);
        Storage::disk('public')->assertMissing($image->file_path);
    });

    expect(ActivityLog::query()->where('activity_log_action', ActivityActionEnum::STORAGE_SYNC)->count())->toBe(1);
});

test('a sync cannot start without the keys', function () {
    switchR2(true);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.configs.file-storage')
        ->call('startSync')
        ->assertSet('syncing', false);
});

test('the logo is written to R2 and found there again', function () {
    withR2Keys();
    switchR2(true);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.configs.site-config')
        ->set('logoUpload', UploadedFile::fake()->image('logo.png'))
        ->call('save')
        ->assertHasNoErrors();

    $path = data_get(app(SiteConfigurationService::class)->getConfigs(raw: true), 'logo');

    Storage::disk('r2')->assertExists($path);
    expect(kSiteConfig('logo'))->toBe(Storage::disk('r2')->url($path));
});

test('the switch and the public address are saved from File storage', function () {
    withR2Keys();

    Livewire::actingAs($this->admin)
        ->test('pages::admin.configs.file-storage')
        ->set('config.storage.cloud', true)
        ->set('config.storage.public-url', 'https://cdn.example.com/')
        ->call('save')
        ->assertHasNoErrors();

    expect(kSiteFlag('storage', 'cloud', false))->toBeTrue()
        ->and(data_get(app(SiteConfigurationService::class)->getConfigs(raw: true), 'storage.public-url'))->toBe('https://cdn.example.com');

    app(CloudStorageService::class)->apply();

    expect(config('filesystems.disks.r2.url'))->toBe('https://cdn.example.com');
});

test('a public address that is not https is refused', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.configs.file-storage')
        ->set('config.storage.cloud', true)
        ->set('config.storage.public-url', 'ftp://media.example.com')
        ->call('save')
        ->assertHasErrors(['config.storage.public-url']);
});

test('the screen is behind its gate', function () {
    $clerk = adminWithRoles(roleWithGates('Clerk', ['finance.transactions' => 'full']));

    $this->actingAs($clerk)->get(route('admin.config.file-storage'))->assertNotFound();
    $this->actingAs($this->admin)->get(route('admin.config.file-storage'))->assertSuccessful();
});
