<?php

use App\Enums\StatusDefault;
use App\Models\Country;
use App\Models\Region;
use Database\Seeders\CountrySeeder;
use Database\Seeders\RegionSeeder;

test('every country gets its states from the bundled file, and a re-seed adds nothing', function () {
    $this->seed([CountrySeeder::class, RegionSeeder::class]);

    $nigeria = Country::query()->where('iso2', 'NG')->sole();

    expect($nigeria->regions()->count())->toBe(37)
        ->and($nigeria->regions()->where('name', 'Lagos')->exists())->toBeTrue();

    $count = Region::query()->count();

    // A region an admin switched off stays off.
    $nigeria->regions()->where('name', 'Lagos')->update(['status' => StatusDefault::INACTIVE]);

    $this->seed(RegionSeeder::class);

    expect(Region::query()->count())->toBe($count)
        ->and($nigeria->regions()->where('name', 'Lagos')->sole()->status)->toBe(StatusDefault::INACTIVE);
});
