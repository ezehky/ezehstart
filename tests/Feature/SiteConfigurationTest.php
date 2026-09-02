<?php

use App\Enums\UserRoleEnum;
use App\Services\SiteConfigurationService;

// test('a fresh install reads no stored configuration', function () {
//     expect(app(SiteConfigurationService::class)->getConfigs(raw: true))->toBe([]);
// });

// test('seeding lays down the initial shape', function () {
//     app(SiteConfigurationService::class)->update(initials: true);

//     $stored = app(SiteConfigurationService::class)->getConfigs(raw: true);

//     expect($stored)->toHaveKeys(['name', 'email', 'email-settings', 'user'])
//         ->and($stored['email-settings']['verification'])->toBeTrue();
// });

// test('an update is visible in the same request', function () {
//     $service = app(SiteConfigurationService::class);

//     $service->update(initials: true);
//     expect(kSiteConfig('name'))->toBe(config('app.name'));

//     $service->update([...$service->getConfigs(raw: true), 'name' => 'Renamed']);

//     // Not just in the cache: config('_site-config') is re-injected, so the page
//     // that saved sees its own change.
//     expect(kSiteConfig('name'))->toBe('Renamed');
// });

// test('a missing key falls back to the given default', function () {
//     expect(kSiteConfig('nothing.here', default: 'fallback'))->toBe('fallback');
// });

// test('an admin can save the site configuration', function () {
//     $admin = userWithRole(UserRoleEnum::ADMIN);
//     app(SiteConfigurationService::class)->update(initials: true);

//     Livewire::actingAs($admin)
//         ->test('pages::admin.configs.site-config')
//         ->set('config.name', 'Ezeh Start')
//         ->call('save')
//         ->assertHasNoErrors();

//     expect(app(SiteConfigurationService::class)->getConfigs(raw: true)['name'])->toBe('Ezeh Start');
// });
