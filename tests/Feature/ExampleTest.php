<?php

use App\Enums\UserTypeEnum;

test('returns a successful response', function () {
    $response = $this->get('/');

    $response->assertOk();
});

test('the landing page routes guests into the auth flow', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee(route('register'))
        ->assertSee(route('login'));
});

test('the landing page points a signed-in user at their own workspace', function () {
    $this->actingAs(userOfType(UserTypeEnum::ADMIN))
        ->get('/')
        ->assertOk()
        ->assertSee(route('admin.dashboard'));
});
