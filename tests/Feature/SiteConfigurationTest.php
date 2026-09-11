<?php

use App\Enums\UserTypeEnum;
use App\Services\SiteConfigurationService;
use Livewire\Livewire;

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
//     $admin = userOfType(UserTypeEnum::ADMIN);
//     app(SiteConfigurationService::class)->update(initials: true);

//     Livewire::actingAs($admin)
//         ->test('pages::admin.configs.site-config')
//         ->set('config.name', 'Ezeh Start')
//         ->call('save')
//         ->assertHasNoErrors();

//     expect(app(SiteConfigurationService::class)->getConfigs(raw: true)['name'])->toBe('Ezeh Start');
// });

// |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
// CONDITIONAL FIELDS

beforeEach(function () {
    $this->admin = userOfType(UserTypeEnum::ADMIN);

    app(SiteConfigurationService::class)->update(initials: true);
});

test('a dependent field is only asked for while its switch is on', function () {
    $component = Livewire::actingAs($this->admin)
        ->test('pages::admin.configs.security')
        ->set('config.security.password-history', false)
        // A depth that would fail min:1 if the rule were still being applied.
        ->set('config.security.password-history-depth', 0)
        ->call('save');

    $component->assertHasNoErrors();
});

test('a dependent field is validated again once its switch comes back on', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.configs.security')
        ->set('config.security.password-history', true)
        ->set('config.security.password-history-depth', 0)
        ->call('save')
        ->assertHasErrors('config.security.password-history-depth');
});

test('the account deletion window follows its own switch', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.configs.security')
        ->set('config.user.account-deletion', false)
        ->set('config.user.account-deletion-days', 0)
        ->call('save')
        ->assertHasNoErrors();

    Livewire::actingAs($this->admin)
        ->test('pages::admin.configs.security')
        ->set('config.user.account-deletion', true)
        ->set('config.user.account-deletion-days', 0)
        ->call('save')
        ->assertHasErrors('config.user.account-deletion-days');
});

test('strict verification is only asked for while verification is on', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.configs.security')
        ->set('config.email-settings.verification', false)
        ->set('config.email-settings.verification-strict', null)
        ->call('save')
        ->assertHasNoErrors();
});

test('a switch turned off stays off after a save', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.configs.security')
        ->set('config.security.passwordless-login', false)
        ->call('save')
        ->assertHasNoErrors();

    // Read with kSiteFlag rather than kSiteConfig: a deliberate false would come
    // back as the default through kSiteConfig's falsy check.
    expect(kSiteFlag('security', 'passwordless-login', true))->toBeFalse();
});
