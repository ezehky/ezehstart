<?php

use App\Enums\LocaleEnum;
use App\Enums\UserTypeEnum;
use App\Services\LocaleService;
use App\Services\SiteConfigurationService;
use App\Services\TranslationFileService;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;

test('only languages with a lang file are offered', function () {
    $available = LocaleEnum::available();

    expect($available)->toContain(LocaleEnum::EN)
        ->toContain(LocaleEnum::FR)
        ->not->toContain(LocaleEnum::DE);
});

test('a guest sees the site default', function () {
    app(SiteConfigurationService::class)->update(['localization' => ['default' => 'fr']]);

    $this->get(route('login'))->assertOk()->assertSee('lang="fr"', false);
});

test('a signed-in account sees its own language', function () {
    $member = userOfType(UserTypeEnum::USER, ['locale' => 'fr', 'email_verified_at' => now()]);

    $this->actingAs($member)->get(route('user.dashboard'))->assertOk()->assertSee('lang="fr"', false);
});

test('a language with no lang file is ignored', function () {
    $member = userOfType(UserTypeEnum::USER, ['locale' => 'de']);

    expect(app(LocaleService::class)->resolve($member))->toBe(LocaleEnum::EN);
});

test('with the switcher off everybody gets the default', function () {
    app(SiteConfigurationService::class)->update(['localization' => ['switcher' => false]]);

    $member = userOfType(UserTypeEnum::USER, ['locale' => 'fr']);

    expect(app(LocaleService::class)->resolve($member))->toBe(LocaleEnum::EN);
});

test('choosing a language from the menu saves it on the account', function () {
    $member = userOfType(UserTypeEnum::USER);

    Livewire::actingAs($member)
        ->test('livewire.language-switcher')
        ->call('choose', 'fr')
        ->assertRedirect();

    expect($member->fresh()->locale)->toBe('fr')
        ->and(session(LocaleService::SESSION_KEY))->toBe('fr');
});

test('choosing the default stores null so a later default carries the account along', function () {
    $member = userOfType(UserTypeEnum::USER, ['locale' => 'fr']);

    Livewire::actingAs($member)->test('livewire.language-switcher')->call('choose', 'en');

    expect($member->fresh()->locale)->toBeNull();
});

test('a guest choice lives in the session', function () {
    Livewire::test('livewire.language-switcher')->call('choose', 'fr');

    expect(session(LocaleService::SESSION_KEY))->toBe('fr');
});

test('the login screen is translated', function () {
    session()->put(LocaleService::SESSION_KEY, 'fr');

    $this->get(route('login'))->assertSee(__('Sign in to your account', locale: 'fr'));

    expect(__('Sign in to your account', locale: 'fr'))->not->toBe('Sign in to your account');
});

test('mail is written in the recipient language', function () {
    $member = userOfType(UserTypeEnum::USER, ['locale' => 'fr']);

    expect($member->preferredLocale())->toBe('fr');
});

test('the extractor finds wrapped sentences and skips group keys', function () {
    $dir = storage_path('framework/testing/lang-scan');
    File::ensureDirectoryExists($dir);
    File::put("{$dir}/sample.blade.php", <<<'BLADE'
        {{ __('Hello there') }} @lang("It's here") {{ __('validation.required') }} {{ __($dynamic) }}
        BLADE);

    $keys = app(TranslationFileService::class)->extract([$dir]);

    File::deleteDirectory($dir);

    expect($keys)->toBe(['Hello there', "It's here"]);
});
