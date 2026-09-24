<?php

use Illuminate\Support\Facades\Blade;
use Illuminate\View\ViewException;

test('a dynamic counter boots the count and arrives showing the final figure', function () {
    $html = Blade::render('<x-util.counter :to="12500" label="Members" />');

    expect($html)
        ->toContain('counter({ from: 0')
        ->toContain('x-intersect.half="enter()"')
        ->toContain('x-text="display">12,500</span>')
        ->toContain('Members');
});

test('a static counter prints the number and boots nothing', function () {
    $html = Blade::render('<x-util.counter :to="12500" variant="static" />');

    expect($html)
        ->toContain('12,500')
        ->not->toContain('x-data')
        ->not->toContain('x-intersect');
});

test('only replay always winds the count back when it leaves the viewport', function () {
    expect(Blade::render('<x-util.counter :to="10" />'))->not->toContain('x-intersect:leave')
        ->and(Blade::render('<x-util.counter :to="10" replay="always" />'))->toContain('x-intersect:leave="leave()"');
});

test('the plus and the prefix frame the number, and a screen reader hears it once', function () {
    $html = Blade::render('<x-util.counter :to="1500" plus prefix="$" :decimals="1" />');

    expect($html)
        ->toContain('<span class="sr-only">$1,500.0+</span>')
        ->toContain('decimals: 1');
});

test('an icon sits on a tinted tile by default, or bare when asked', function () {
    $boxed = Blade::render('<x-util.counter :to="10" icon="users" icon-tone="sky" />');
    $plain = Blade::render('<x-util.counter :to="10" icon="users" icon-style="plain" />');

    expect($boxed)->toContain('bg-sky-50')->toContain('<svg')
        ->and($plain)->not->toContain('rounded-xl')->toContain('text-lime-700')->toContain('<svg')
        ->and(Blade::render('<x-util.counter :to="10" />'))->not->toContain('<svg');
});

test('a centred counter centres its icon, figure and label together', function () {
    expect(Blade::render('<x-util.counter :to="10" align="center" />'))->toContain('items-center text-center');
});

test('an unknown option is refused rather than guessed at', function (string $attributes) {
    Blade::render("<x-util.counter :to=\"10\" {$attributes} />");
})->with([
    'variant' => 'variant="spinning"',
    'replay' => 'replay="twice"',
    'align' => 'align="justify"',
    'icon style' => 'icon="users" icon-style="glowing"',
])->throws(ViewException::class);
