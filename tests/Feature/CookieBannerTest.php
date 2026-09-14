<?php

use App\Enums\UserTypeEnum;
use App\Services\SiteConfigurationService;
use Livewire\Livewire;

test('the cookie notice shows until the site turns it off', function () {
    app(SiteConfigurationService::class)->update(initials: true);

    $this->get(route('home'))
        ->assertSuccessful()
        ->assertSee('aria-label="Cookie notice"', escape: false);
});

test('the cookie notice can be turned off', function () {
    app(SiteConfigurationService::class)->update(['preferences' => ['accept-cookies' => false]]);

    // A switch saved as false has to read back as false. kSiteConfig() would hand
    // back the default here and the notice would be impossible to turn off.
    $this->get(route('home'))
        ->assertSuccessful()
        ->assertDontSee('aria-label="Cookie notice"', escape: false);
});

test('the notice reaches the signed-in workspaces too', function () {
    app(SiteConfigurationService::class)->update(initials: true);

    $member = userOfType(UserTypeEnum::USER, ['email_verified_at' => now()]);

    // It is about the session cookie, which is the one thing the public pages do
    // not set — so it belongs in the base shell rather than the public one.
    $this->actingAs($member)
        ->get(route('user.dashboard'))
        ->assertSuccessful()
        ->assertSee('aria-label="Cookie notice"', escape: false);
});

test('it links to the published cookie policy', function () {
    app(SiteConfigurationService::class)->update(initials: true);

    $this->get(route('home'))
        ->assertSuccessful()
        ->assertSee(route('cookies'));
});

test('an administrator can turn the notice off from the preferences screen', function () {
    $admin = userOfType(UserTypeEnum::ADMIN, ['email_verified_at' => now()]);

    app(SiteConfigurationService::class)->update(initials: true);

    Livewire::actingAs($admin)
        ->test('pages::admin.configs.preferences')
        ->set('config.preferences.accept-cookies', false)
        ->call('save')
        ->assertHasNoErrors();

    expect(kSiteFlag('preferences', 'accept-cookies', true))->toBeFalse();
});
