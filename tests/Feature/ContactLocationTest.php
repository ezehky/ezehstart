<?php

use App\Enums\UserTypeEnum;
use App\Services\SiteConfigurationService;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = userOfType(UserTypeEnum::ADMIN);

    // The defaults carry placeholder locations; most tests here start from none.
    $service = app(SiteConfigurationService::class);
    $service->update(initials: true);
    $service->update([...$service->getConfigs(raw: true), 'contact-locations' => []]);
});

const GOOGLE_EMBED = 'https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3963.5';

// |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
// THE MAP LINK

test('a pasted google iframe is reduced to its link', function () {
    $pasted = '<iframe src="'.GOOGLE_EMBED.'&amp;hl=en" width="600" height="450" style="border:0;" allowfullscreen=""></iframe>';

    expect(app(SiteConfigurationService::class)->mapEmbedUrl($pasted))->toBe(GOOGLE_EMBED.'&hl=en');
});

test('only google maps and openstreetmap embeds are accepted', function (?string $value, bool $accepted) {
    expect(app(SiteConfigurationService::class)->mapEmbedUrl($value) !== null)->toBe($accepted);
})->with([
    'google embed' => [GOOGLE_EMBED, true],
    'google output=embed' => ['https://maps.google.com/maps?q=Lagos&output=embed', true],
    'openstreetmap embed' => ['https://www.openstreetmap.org/export/embed.html?bbox=3.3,6.5,3.4,6.6', true],
    'google address-bar link' => ['https://www.google.com/maps/place/Lagos', false],
    'plain http' => ['http://www.google.com/maps/embed?pb=x', false],
    'another host' => ['https://evil.example/maps/embed?pb=x', false],
    'javascript' => ['javascript:alert(1)', false],
    'empty' => [null, false],
]);

// |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
// THE ADMIN SCREEN

test('an admin can add a location with several phones, emails and a pasted map', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.configs.contact-locations')
        ->call('create')
        ->set('location.label', 'Head office')
        ->set('location.address', '12 Allen Avenue, Ikeja')
        ->set('location.phones', ['+2348000000001', '', '+2348000000002'])
        ->set('location.emails', ['hq@example.com', 'sales@example.com'])
        ->set('location.map-embed', '<iframe src="'.GOOGLE_EMBED.'"></iframe>')
        ->call('save')
        ->assertHasNoErrors();

    $stored = app(SiteConfigurationService::class)->getConfigs(raw: true)['contact-locations'];

    expect($stored)->toHaveCount(1)
        // The blank row is dropped, and only the link of the iframe is kept.
        ->and($stored[0]['phones'])->toBe(['+2348000000001', '+2348000000002'])
        ->and($stored[0]['emails'])->toBe(['hq@example.com', 'sales@example.com'])
        ->and($stored[0]['map-embed'])->toBe(GOOGLE_EMBED);
});

test('a map link that would not show is refused on the form', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.configs.contact-locations')
        ->call('create')
        ->set('location.label', 'Head office')
        ->set('location.map-embed', 'https://www.google.com/maps/place/Lagos')
        ->call('save')
        ->assertHasErrors('location.map-embed');
});

test('a location with nothing a visitor could use is not saved', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.configs.contact-locations')
        ->call('create')
        ->set('location.label', 'Empty office')
        ->call('save');

    expect(app(SiteConfigurationService::class)->getConfigs(raw: true)['contact-locations'])->toBe([]);
});

test('a fresh install ships placeholder locations on the home page', function () {
    app(SiteConfigurationService::class)->update(initials: true);

    $this->get(route('home'))
        ->assertOk()
        ->assertSee('id="contact"', false)
        ->assertSee('Head office')
        ->assertSee('Abuja branch');
});

test('deleting placeholders sticks, rather than the defaults merging back in', function () {
    $service = app(SiteConfigurationService::class);
    $service->update(initials: true);

    $component = Livewire::actingAs($this->admin)->test('pages::admin.configs.contact-locations');
    $component->call('confirmDelete', 0)->call('delete');
    $component->call('confirmDelete', 0)->call('delete');

    expect($service->getConfigs(raw: true)['contact-locations'])->toHaveCount(1);

    // Another config screen saves the merged config whole, so it must not bring the
    // two deleted defaults back with it.
    Livewire::actingAs($this->admin)
        ->test('pages::admin.configs.site-config')
        ->set('config.name', 'Renamed')
        ->call('save');

    expect($service->getConfigs(raw: true)['contact-locations'])->toHaveCount(1)
        ->and($service->getConfigs(raw: true)['contact-locations'][0]['label'])->toBe('Support desk');
});

test('a location can be deleted', function () {
    $service = app(SiteConfigurationService::class);
    $service->update([...$service->getConfigs(raw: true), 'contact-locations' => [
        ['label' => 'Head office', 'address' => '12 Allen Avenue', 'phones' => [], 'emails' => [], 'map-embed' => ''],
    ]]);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.configs.contact-locations')
        ->call('confirmDelete', 0)
        ->call('delete');

    expect($service->getConfigs(raw: true)['contact-locations'])->toBe([]);
});

// |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
// THE PUBLIC SECTION

test('the contact section stays off the home page until a location is added', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertDontSee('id="contact"', false);
});

test('each location shows on the home page, with a map only where one was given', function () {
    $service = app(SiteConfigurationService::class);
    $service->update([...$service->getConfigs(raw: true), 'contact-locations' => [
        ['label' => 'Head office', 'address' => '12 Allen Avenue', 'phones' => ['+2348000000001'], 'emails' => ['hq@example.com'], 'map-embed' => GOOGLE_EMBED],
        ['label' => 'Abuja branch', 'address' => '3 Wuse Close', 'phones' => [], 'emails' => [], 'map-embed' => ''],
    ]]);

    $response = $this->get(route('home'))->assertOk();

    $response->assertSee('Head office')
        ->assertSee('Abuja branch')
        ->assertSee('hq@example.com')
        ->assertSee('tel:+2348000000001', false);

    expect(substr_count($response->getContent(), '<iframe'))->toBe(1);
});

test('a map edited by hand into the file is still held to the embed rule', function () {
    $service = app(SiteConfigurationService::class);
    $service->update([...$service->getConfigs(raw: true), 'contact-locations' => [
        ['label' => 'Head office', 'address' => '12 Allen Avenue', 'phones' => [], 'emails' => [], 'map-embed' => 'https://evil.example/frame'],
    ]]);

    $this->get(route('home'))
        ->assertOk()
        ->assertSee('Head office')
        ->assertDontSee('evil.example');
});
