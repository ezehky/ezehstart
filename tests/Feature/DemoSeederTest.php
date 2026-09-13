<?php

use App\Enums\UserTypeEnum;
use App\Services\DashboardManagerService;
use App\Services\ImageLibraryService;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');

    // tests/Pest.php already fakes the local disk for the site configuration, and
    // the dashboard-manager files sit on that same default disk.
});

test('the demo seeder clears the image library off disk', function () {
    // A file from a previous run, which migrate:fresh leaves behind because it
    // drops tables and never touches the filesystem.
    Storage::disk('public')->put(ImageLibraryService::STORAGE_PATH.'/7/orphan.jpg', 'stale');

    $this->seed(DemoSeeder::class);

    Storage::disk('public')->assertMissing(ImageLibraryService::STORAGE_PATH.'/7/orphan.jpg');
});

test('the demo seeder leaves the site branding alone', function () {
    // The logo and favicon are the install's own, not demo data, and they live on
    // the same disk as the library.
    Storage::disk('public')->put('site-config/site-logo.png', 'logo');

    $this->seed(DemoSeeder::class);

    Storage::disk('public')->assertExists('site-config/site-logo.png');
});

test('the demo seeder clears the saved dashboard arrangements', function () {
    // Named after the account id, and migrate:fresh restarts ids from one — so a
    // file left behind is inherited by whoever gets that id next.
    Storage::put(DashboardManagerService::STORAGE_PATH.'/user-3.json', '{"column-manager":{}}');

    $this->seed(DemoSeeder::class);

    Storage::assertMissing(DashboardManagerService::STORAGE_PATH.'/user-3.json');
});

test('a reseeded account does not inherit the previous run arrangement', function () {
    $service = app(DashboardManagerService::class);
    $previous = userOfType(UserTypeEnum::USER);

    $service->put('column-manager', 'admin.transactions', ['gateway'], $previous);

    expect($service->get('column-manager', 'admin.transactions', user: $previous))->toBe(['gateway']);

    $this->seed(DemoSeeder::class);

    // The singleton caches what it has read, so the assertion is about the disk
    // rather than about the instance that wrote it.
    expect(Storage::exists($service->file($previous)))->toBeFalse();
});
