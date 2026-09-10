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
