<?php

use App\Enums\CategoryGroupEnum;
use App\Enums\StatusUser;
use App\Enums\UserTypeEnum;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = userOfType(UserTypeEnum::ADMIN);
});

test('an admin can open every workspace page', function (string $route) {
    // The category screen is one screen per vocabulary rather than one per table.
    $parameters = $route === 'admin.categories' ? [CategoryGroupEnum::BLOG] : [];

    $this->actingAs($this->admin)->get(route($route, $parameters))->assertSuccessful();
})->with([
    'admin.dashboard',
    'admin.profile',
    'admin.config.site',
    'admin.config.security',
    'admin.config.social-handles',
    'admin.config.policies',
    'admin.config.faqs',
    'admin.admins',
    'admin.users',
    'admin.roles',
    'admin.activity-logs',
    'admin.config.notification-types',
    'admin.image-library',
    'admin.video-library',
    'admin.transactions',
    'admin.blog.blogs',
    'admin.categories',
    'admin.tags',
]);

test('an admin can open a single account', function () {
    $account = userOfType(UserTypeEnum::USER);

    $this->actingAs($this->admin)
        ->get(route('admin.user', $account))
        ->assertSuccessful()
        ->assertSee($account->name);
});

test('the users listing only shows member accounts', function () {
    $member = userOfType(UserTypeEnum::USER, ['name' => 'Ada Member']);
    $stranger = adminWithoutRole(['name' => 'Grace Unassigned']);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.users.users')
        ->assertSee($member->name)
        ->assertDontSee($stranger->name);
});

test('the admins listing can be narrowed to those with no live role', function () {
    $member = userOfType(UserTypeEnum::USER, ['name' => 'Ada Member']);
    $stranger = adminWithoutRole(['name' => 'Grace Unassigned']);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.users.admins')
        ->set('roleState', 'none')
        ->assertSee($stranger->name)
        ->assertDontSee($member->name)
        // The signed-in admin is on the protected role, so it is not stranded.
        ->assertDontSee($this->admin->name);
});

test('the users listing can be searched', function () {
    userOfType(UserTypeEnum::USER, ['name' => 'Ada Lovelace']);
    userOfType(UserTypeEnum::USER, ['name' => 'Grace Hopper']);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.users.users')
        ->set('search', 'Lovelace')
        ->assertSee('Ada Lovelace')
        ->assertDontSee('Grace Hopper');
});

test('an admin can suspend and reactivate an account', function () {
    $account = userOfType(UserTypeEnum::USER);

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
