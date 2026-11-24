<?php

use App\Enums\UserTypeEnum;
use App\Services\PageTranslatorService;
use App\Services\SiteConfigurationService;
use Livewire\Livewire;

beforeEach(function () {
    app(SiteConfigurationService::class)->update(initials: true);

    $this->admin = userOfType(UserTypeEnum::ADMIN, ['email_verified_at' => now()]);
});

test('the translate menu is off until it is switched on', function () {
    expect(app(PageTranslatorService::class)->enabled())->toBeFalse();

    $this->get(route('home'))->assertDontSee('google_translate_element', false);
});

test('switched on, the public header offers each chosen language', function () {
    app(SiteConfigurationService::class)->update(['translator' => ['status' => true, 'languages' => ['yo', 'fr']]]);

    $this->get(route('home'))
        ->assertSee('google_translate_element', false)
        ->assertSee('Yorùbá')
        ->assertSee('Français');
});

test('a language takes its name and flag from the catalogue', function () {
    app(SiteConfigurationService::class)->update(['translator' => ['status' => true, 'languages' => ['sw', 'nope']]]);

    $languages = app(PageTranslatorService::class)->languages();

    // An unknown code is dropped rather than shown with no name.
    expect($languages->keys()->all())->toBe(['sw'])
        ->and($languages['sw']['flag'])->toBe('KE')
        ->and($languages['sw']['native'])->toBe('Kiswahili');
});

test('an administrator adds and removes languages from preferences', function () {
    app(SiteConfigurationService::class)->update(['translator' => ['status' => true, 'languages' => ['fr']]]);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.configs.preferences')
        ->set('translatorAdd', 'ig')
        ->call('addTranslatorLanguage')
        ->assertHasNoErrors()
        ->call('removeTranslatorLanguage', 'fr')
        ->call('save')
        ->assertHasNoErrors();

    expect(kSiteFlag('translator', 'languages'))->toBe(['ig']);
});

test('a language the catalogue does not know cannot be added', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.configs.preferences')
        ->set('config.translator.status', true)
        ->set('translatorAdd', 'klingon')
        ->call('addTranslatorLanguage')
        ->assertHasErrors('translatorAdd');
});

test('a language already on the menu is not offered again', function () {
    app(SiteConfigurationService::class)->update(['translator' => ['status' => true, 'languages' => ['fr']]]);

    expect(app(PageTranslatorService::class)->addable(['fr'])->has('fr'))->toBeFalse();
});
