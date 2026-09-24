<?php

use App\Enums\UserTypeEnum;

test('an install nobody has signed up to shows no stats row', function () {
    userOfType(UserTypeEnum::ADMIN);

    $this->get(route('home'))
        ->assertOk()
        ->assertDontSee('Articles published');
});

test('the home page counts its members up from zero', function () {
    userOfType(UserTypeEnum::USER);
    userOfType(UserTypeEnum::USER);

    $this->get(route('home'))
        ->assertOk()
        ->assertSee('Members')
        ->assertSee('Articles published')
        ->assertSee('counter({ from: 0, to: 2', false)
        ->assertSee('<span class="sr-only">2+</span>', false);
});
