<?php

use App\Enums\BrandPaletteEnum;
use App\Enums\UserTypeEnum;
use App\Services\BrandColorService;
use App\Services\SiteConfigurationService;
use Livewire\Livewire;

beforeEach(function () {
    app(SiteConfigurationService::class)->update(initials: true);

    $this->admin = userOfType(UserTypeEnum::ADMIN, ['email_verified_at' => now()]);
});

test('the compiled teal prints nothing over the stylesheet', function () {
    expect(kBrandStyles())->toBe('');
});

test('a preset repaints the brand scale with its colour on the 400 shade', function () {
    app(SiteConfigurationService::class)->update(['brand' => ['palette' => BrandPaletteEnum::ROSE->value]]);

    expect(kBrandStyles())->toContain('--color-brand-400:#fb7185')
        ->and(kBrandColor())->toBe('#fb7185');
});

test('a custom colour is read from the stored hex', function () {
    app(SiteConfigurationService::class)->update(['brand' => ['palette' => 'custom', 'primary' => '#1E40AF']]);

    expect(kBrandColor())->toBe('#1e40af')
        // Deep enough for white button text.
        ->and(kBrandStyles())->toContain('--color-accent-foreground:#ffffff');
});

test('a pale colour gets dark button text from its own scale', function () {
    $service = app(BrandColorService::class);

    expect($service->foreground('#2dd4b5'))->toBe($service->scale('#2dd4b5')[900]);
});

test('every scale has eleven shades with the chosen colour on the anchor', function () {
    foreach (BrandPaletteEnum::presets() as $palette) {
        $scale = app(BrandColorService::class)->scale($palette->primary());

        expect($scale)->toHaveCount(11)
            ->and($scale[BrandColorService::ANCHOR])->toBe($palette->primary());
    }
});

test('the brand screen saves a preset', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.configs.brand-colors')
        ->call('pickPalette', BrandPaletteEnum::VIOLET->value)
        ->call('save')
        ->assertHasNoErrors();

    expect(kSiteConfig('brand.palette'))->toBe(BrandPaletteEnum::VIOLET->value);
});

test('a custom colour must be a hex', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.configs.brand-colors')
        ->call('pickPalette', BrandPaletteEnum::CUSTOM->value)
        ->set('config.brand.primary', 'blue')
        ->call('save')
        ->assertHasErrors('config.brand.primary');
});

test('the base layout prints the chosen colour', function () {
    app(SiteConfigurationService::class)->update(['brand' => ['palette' => BrandPaletteEnum::AMBER->value]]);

    $this->get(route('home'))->assertSee('--color-brand-400:#fbbf24', false);
});
