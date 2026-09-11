<?php

use App\Enums\UserTypeEnum;
use Illuminate\Support\Facades\Blade;

/**
 * The breadcrumb and the page header both read the site title back rather than
 * being told where they are, so every test here sets a title first and then asks
 * what came out.
 */
beforeEach(function () {
    $this->actingAs(userOfType(UserTypeEnum::ADMIN));
});

// |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
// THE TRAIL

test('the trail follows the site title through the navigation tree', function () {
    kSetSiteTitle('users', 'roles');

    $trail = kBreadcrumbTrail('admin');

    expect($trail)->toHaveCount(2);
    expect($trail[0]['label'])->toBe('Users');
    expect($trail[1]['label'])->toBe('Roles');

    // "Users" is a parent with children and no screen of its own, and the page you
    // are already on is not somewhere to navigate to.
    expect($trail[0]['link'])->toBeNull();
    expect($trail[1]['link'])->toBeNull();
});

test('a segment above the current page keeps its link', function () {
    kSetSiteTitle('content', 'blogs', 'new post');

    $trail = kBreadcrumbTrail('admin');

    expect($trail)->toHaveCount(3);
    expect($trail[1]['label'])->toBe('Blogs');
    expect($trail[1]['link'])->toBe(route('admin.blog.blogs'));
    expect($trail[2]['link'])->toBeNull();
});

test('a segment the tree does not know still appears, unlinked', function () {
    kSetSiteTitle('users', 'users', 'Ada Lovelace', format: false);

    $trail = kBreadcrumbTrail('admin');

    expect($trail[2]['label'])->toBe('Ada Lovelace');
    expect($trail[2]['link'])->toBeNull();
});

test('a page with no title has no trail', function () {
    config(['_setups.title' => '']);

    expect(kBreadcrumbTrail('admin'))->toBe([]);
});

// |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
// THE BREADCRUMB

test('the breadcrumb renders a crumb per segment', function () {
    kSetSiteTitle('users', 'roles');

    $html = Blade::render('<x-dashboard.breadcrumb />');

    expect($html)
        ->toContain('Users')
        ->toContain('Roles')
        ->toContain('data-flux-breadcrumbs');
});

test('an explicit trail overrides the title and still unlinks its last crumb', function () {
    kSetSiteTitle('users', 'users');

    $html = Blade::render(
        '<x-dashboard.breadcrumb :items="$items" />',
        ['items' => ['Ledger' => route('admin.transactions'), 'Receipt' => route('admin.users')]]
    );

    expect($html)
        ->toContain('Ledger')
        ->toContain('Receipt')
        ->toContain(route('admin.transactions'))
        ->not->toContain('users')
        ->not->toContain(route('admin.users'));
});

// |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
// THE HEADER

test('the header titles itself from the deepest segment', function () {
    kSetSiteTitle('users', 'roles');

    $html = Blade::render('<x-dashboard.page-header />');

    // The crumbs above already say "Users"; repeating it in the title reads as a
    // file path rather than a heading.
    expect($html)
        ->toContain('>Roles<')
        ->not->toContain('Users / Roles');
});

test('the header renders the subtitle the title carried', function () {
    kSetSiteTitle('users', 'users', subtitle: 'Everyone in the member workspace.');

    expect(Blade::render('<x-dashboard.page-header />'))
        ->toContain('Everyone in the member workspace.');
});

test('an explicit title and subtitle win over the site title', function () {
    kSetSiteTitle('users', 'users', subtitle: 'From the title.');

    $html = Blade::render('<x-dashboard.page-header title="Ledger" subtitle="From the caller." />');

    expect($html)
        ->toContain('Ledger')
        ->toContain('From the caller.')
        ->not->toContain('From the title.');
});

test('the actions slot renders beside the heading', function () {
    kSetSiteTitle('users', 'users');

    $html = Blade::render(
        '<x-dashboard.page-header><x-slot:actions><flux:button>Add member</flux:button></x-slot:actions></x-dashboard.page-header>'
    );

    expect($html)->toContain('Add member');
});

test('a paused title keeps the actions and drops the heading', function () {
    kSetSiteTitle('users', 'roles');
    kPauseSiteTitle();

    $html = Blade::render(
        '<x-dashboard.page-header><x-slot:actions><flux:button>Add member</flux:button></x-slot:actions></x-dashboard.page-header>'
    );

    expect($html)
        ->toContain('Add member')
        ->not->toContain('Roles')
        ->not->toContain('data-flux-breadcrumbs');
});

test('the back link the title set is rendered, and false turns it off', function () {
    kSetSiteTitle('users', 'users');
    kBackForwardLink('admin.users', label: 'Back to users');

    expect(Blade::render('<x-dashboard.page-header />'))
        ->toContain('Back to users')
        ->toContain(route('admin.users'));

    expect(Blade::render('<x-dashboard.page-header :back="false" />'))
        ->not->toContain('Back to users');
});

test('a back link keeps SPA navigation unless the link turned it off', function () {
    kSetSiteTitle('users', 'users');

    kBackForwardLink('admin.users', label: 'Back to users');
    expect(Blade::render('<x-dashboard.page-header :breadcrumb="false" />'))->toContain('wire:navigate');

    kBackForwardLink('admin.users', label: 'Back to users', spa: false);
    expect(Blade::render('<x-dashboard.page-header :breadcrumb="false" />'))->not->toContain('wire:navigate');
});

test('a back arrow leads the label and a forward arrow trails it', function () {
    kSetSiteTitle('users', 'users');

    // Flux inlines its icons as SVG, so the arrow is found by where the markup
    // sits relative to the label rather than by an icon name. The breadcrumb is
    // off because its own separators are SVGs too.
    kBackForwardLink('admin.users', label: 'Back to users');
    $back = Blade::render('<x-dashboard.page-header :breadcrumb="false" />');

    expect(strpos($back, 'data-flux-icon'))->toBeLessThan(strpos($back, 'Back to users'));

    kBackForwardLink('admin.roles', forward: true, label: 'Continue to roles');
    $forward = Blade::render('<x-dashboard.page-header :breadcrumb="false" />');

    expect(strpos($forward, 'Continue to roles'))->toBeLessThan(strpos($forward, 'data-flux-icon'));
});
