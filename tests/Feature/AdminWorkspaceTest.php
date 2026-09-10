<?php

use App\Enums\CategoryGroupEnum;
use App\Enums\StatusUser;
use App\Enums\UserRoleEnum;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = userWithRole(UserRoleEnum::ADMIN);
});

test('an admin can open every workspace page', function (string $route) {
    // The category screen is one screen per vocabulary rather than one per table.
    $parameters = $route === 'admin.categories' ? [CategoryGroupEnum::BLOG] : [];

    $this->actingAs($this->admin)->get(route($route, $parameters))->assertSuccessful();
})->with([
    'admin.dashboard',
    'admin.profile',
    'admin.site-config',
    'admin.config.social-handles',
    'admin.admins',
    'admin.members',
    'admin.unassigned',
    'admin.roles',
    'admin.activity-logs',
    'admin.config.notification-types',
    'admin.image-library',
    'admin.transactions',
    'admin.blog.blogs',
    'admin.categories',
    'admin.tags',
]);

test('an admin can open a single account', function () {
    $account = userWithRole(UserRoleEnum::USER);

    $this->actingAs($this->admin)
        ->get(route('admin.user', $account))
        ->assertSuccessful()
        ->assertSee($account->name);
});

test('the members listing only shows accounts carrying the member role', function () {
    $member = userWithRole(UserRoleEnum::USER, ['name' => 'Ada Member']);
    $stranger = userWithoutRole(['name' => 'Grace Unassigned']);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.users.members')
        ->assertSee($member->name)
        ->assertDontSee($stranger->name);
});

test('the unassigned listing only shows accounts with no role', function () {
    $member = userWithRole(UserRoleEnum::USER, ['name' => 'Ada Member']);
    $stranger = userWithoutRole(['name' => 'Grace Unassigned']);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.users.unassigned')
        ->assertSee($stranger->name)
        ->assertDontSee($member->name);
});

test('the members listing can be searched', function () {
    userWithRole(UserRoleEnum::USER, ['name' => 'Ada Lovelace']);
    userWithRole(UserRoleEnum::USER, ['name' => 'Grace Hopper']);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.users.members')
        ->set('search', 'Lovelace')
        ->assertSee('Ada Lovelace')
        ->assertDontSee('Grace Hopper');
});

test('an admin can suspend and reactivate an account', function () {
    $account = userWithRole(UserRoleEnum::USER);

    $component = Livewire::actingAs($this->admin)
        ->test('pages::admin.users.user-view', ['user' => $account])
        ->call('toggleStatus');

    expect($account->fresh()->status)->toBe(StatusUser::SUSPENDED);

    $component->call('toggleStatus');

    expect($account->fresh()->status)->toBe(StatusUser::ACTIVE);
});

test('an admin cannot suspend their own account', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.users.user-view', ['user' => $this->admin])
        ->call('toggleStatus');

    expect($this->admin->fresh()->status)->toBe(StatusUser::ACTIVE);
});

test('an admin can create another admin', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.users.admins')
        ->set('name', 'Grace Hopper')
        ->set('email', 'grace@example.test')
        ->set('password', 'Password123!')
        ->set('status', true)
        ->call('save');

    $created = User::query()->whereEmail('grace@example.test')->firstOrFail();

    expect($created->isAdmin())->toBeTrue();
});
