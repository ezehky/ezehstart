<?php

use App\Enums\UserTypeEnum;
use App\Services\AnalyticsService;
use App\Services\CookieConsentService;
use App\Services\SiteConfigurationService;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = userOfType(UserTypeEnum::ADMIN, ['email_verified_at' => now()]);
});

function withAnalyticsId(?string $id = 'G-AB12CD34EF'): void
{
    $service = app(SiteConfigurationService::class);
    $service->update(initials: true);
    $service->update([...$service->getConfigs(raw: true), 'analytics' => ['ga4-id' => $id]]);
}

test('without a measurement ID nothing is loaded and the banner stays a notice', function () {
    withAnalyticsId(null);

    $this->get(route('home'))
        ->assertSuccessful()
        ->assertDontSee('googletagmanager.com', escape: false)
        ->assertSee('aria-label="Cookie notice"', escape: false)
        ->assertDontSee('Accept all');
});

test('with an ID the banner asks before anything is measured', function () {
    withAnalyticsId();

    $this->get(route('home'))
        ->assertSuccessful()
        ->assertSee('aria-label="Your cookie choices"', escape: false)
        ->assertSee('Accept all')
        ->assertSee('Cookie settings');

    // The tag waits on the visitor's answer rather than loading.
    expect(app(AnalyticsService::class)->tag())->toMatchArray(['id' => 'G-AB12CD34EF', 'granted' => false]);
});

test('a visitor who allowed measurement gets the tag at once', function () {
    withAnalyticsId();

    $this->withUnencryptedCookie(CookieConsentService::COOKIE, 'v1:necessary,measurement')
        ->get(route('home'))
        ->assertSuccessful()
        ->assertSee('G-AB12CD34EF', escape: false)
        ->assertSee('load();', escape: false);
});

test('a visitor who chose necessary only is not measured', function () {
    withAnalyticsId();

    $html = $this->withUnencryptedCookie(CookieConsentService::COOKIE, 'v1:necessary')
        ->get(route('home'))
        ->getContent();

    expect($html)->not->toMatch('/^\s*load\(\);/m');
});

test('a choice made under an older banner is asked again', function () {
    withAnalyticsId();

    $html = $this->withUnencryptedCookie(CookieConsentService::COOKIE, 'v0:necessary,measurement')
        ->get(route('home'))
        ->getContent();

    expect($html)->not->toMatch('/^\s*load\(\);/m');
});

test('neither workspace is measured', function () {
    withAnalyticsId();

    $member = userOfType(UserTypeEnum::USER, ['email_verified_at' => now()]);

    $this->actingAs($this->admin)->get(route('admin.dashboard'))
        ->assertSuccessful()
        ->assertDontSee('googletagmanager.com', escape: false);

    $this->actingAs($member)->get(route('user.dashboard'))
        ->assertSuccessful()
        ->assertDontSee('googletagmanager.com', escape: false);
});

test('with the banner switched off nobody has agreed, so nothing loads', function () {
    withAnalyticsId();

    $service = app(SiteConfigurationService::class);
    $service->update([...$service->getConfigs(raw: true), 'preferences' => ['accept-cookies' => false]]);

    $response = $this->withUnencryptedCookie(CookieConsentService::COOKIE, 'v1:necessary,measurement')
        ->get(route('home'))
        ->assertSuccessful()
        ->assertDontSee('Your cookie choices');

    expect($response->getContent())->not->toMatch('/^\s*load\(\);/m');
});

test('the measurement ID is saved from Site info the way Google prints it', function () {
    withAnalyticsId(null);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.configs.site-config')
        ->set('config.analytics.ga4-id', '  g-ab12cd34ef ')
        ->call('save')
        ->assertHasNoErrors();

    expect(app(AnalyticsService::class)->measurementId())->toBe('G-AB12CD34EF');
});

test('something that is not a GA4 ID is refused', function () {
    withAnalyticsId(null);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.configs.site-config')
        ->set('config.analytics.ga4-id', 'UA-12345-1')
        ->call('save')
        ->assertHasErrors(['config.analytics.ga4-id']);
});
