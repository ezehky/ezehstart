<?php

use App\Enums\StatusUser;
use App\Enums\UserTypeEnum;

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
    $this->actingAs(userOfType(UserTypeEnum::ADMIN))
        ->get(route('admin.dashboard'))
        ->assertSuccessful();
});

test('a member cannot reach the admin workspace', function () {
    $this->actingAs(userOfType(UserTypeEnum::USER))
        ->get(route('admin.dashboard'))
        ->assertNotFound();
});

test('an admin cannot reach the member workspace', function () {
    $this->actingAs(userOfType(UserTypeEnum::ADMIN))
        ->get(route('user.dashboard'))
        ->assertNotFound();
});

test('an admin with no role lands on the dashboard and reaches nothing else', function () {
    $user = adminWithoutRole();

    // The dashboard and the profile are never gated, so a stranded admin always has
    // somewhere to land — otherwise there would be nowhere for a refusal to send them.
    $this->actingAs($user)->get(route('admin.dashboard'))->assertSuccessful();
    $this->actingAs($user)->get(route('admin.profile'))->assertSuccessful();

    $this->actingAs($user)->get(route('admin.roles'))->assertNotFound();
    $this->actingAs($user)->get(route('user.dashboard'))->assertNotFound();
});

test('a suspended account is signed out and turned away', function () {
    $user = userOfType(UserTypeEnum::ADMIN, ['status' => StatusUser::SUSPENDED]);

    $this->actingAs($user)
        ->get(route('admin.dashboard'))
        ->assertRedirect(route('login'));

    $this->assertGuest();
});

test('signing in updates the last seen timestamp', function () {
    $user = userOfType(UserTypeEnum::ADMIN, ['last_seen_at' => null]);

    $this->actingAs($user)->get(route('admin.dashboard'))->assertSuccessful();

    expect($user->fresh()->last_seen_at)->not->toBeNull();
});

/*
 * redirectUsersTo() reads the account's type to decide where to send it. It was
 * reading ->type, which is not a column on this project's users table — the
 * column is user_type, because type is an SQL keyword the house style bans. It
 * came back null and every guest route a signed-in account touched was a 500.
 */
test('a signed-in account is redirected off a guest screen rather than erroring', function (string $route) {
    $member = userOfType(UserTypeEnum::USER, ['email_verified_at' => now()]);

    $this->actingAs($member)->get(route($route))->assertRedirect(route('user.dashboard'));
})->with([
    'login',
    'register',
    'password.request',
    'passwordless',
    'two-factor.challenge',
]);

test('an administrator lands in the admin workspace instead', function () {
    $admin = userOfType(UserTypeEnum::ADMIN, ['email_verified_at' => now()]);

    $this->actingAs($admin)->get(route('login'))->assertRedirect(route('admin.dashboard'));
});
