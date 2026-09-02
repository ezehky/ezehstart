<?php

use App\Enums\StatusUser;
use App\Enums\UserRoleEnum;

test('guests are sent to the login page', function () {
    $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
    $this->get(route('user.dashboard'))->assertRedirect(route('login'));
});

test('guests can view the authentication screens', function () {
    $this->get(route('login'))->assertSuccessful();
    $this->get(route('register'))->assertSuccessful();
    $this->get(route('password.request'))->assertSuccessful();
    $this->get(route('passwordless'))->assertSuccessful();
});

test('an admin reaches the admin workspace', function () {
    $this->actingAs(userWithRole(UserRoleEnum::ADMIN))
        ->get(route('admin.dashboard'))
        ->assertSuccessful();
});

test('a member cannot reach the admin workspace', function () {
    $this->actingAs(userWithRole(UserRoleEnum::USER))
        ->get(route('admin.dashboard'))
        ->assertNotFound();
});

test('an admin cannot reach the member workspace', function () {
    $this->actingAs(userWithRole(UserRoleEnum::ADMIN))
        ->get(route('user.dashboard'))
        ->assertNotFound();
});

test('an account with no role reaches nothing', function () {
    $user = userWithoutRole();

    $this->actingAs($user)->get(route('admin.dashboard'))->assertNotFound();
    $this->actingAs($user)->get(route('user.dashboard'))->assertNotFound();
});

test('a suspended account is signed out and turned away', function () {
    $user = userWithRole(UserRoleEnum::ADMIN, ['status' => StatusUser::SUSPENDED]);

    $this->actingAs($user)
        ->get(route('admin.dashboard'))
        ->assertRedirect(route('login'));

    $this->assertGuest();
});

test('signing in updates the last seen timestamp', function () {
    $user = userWithRole(UserRoleEnum::ADMIN, ['last_seen_at' => null]);

    $this->actingAs($user)->get(route('admin.dashboard'))->assertSuccessful();

    expect($user->fresh()->last_seen_at)->not->toBeNull();
});
