<?php

use Illuminate\Support\Facades\Blade;
use Illuminate\View\ViewException;

test('a countdown renders only the units it was asked for', function () {
    $html = Blade::render(
        '<x-util.countdown :until="now()->addDays(2)->addHours(3)" units="days,hours" />'
    );

    expect($html)
        ->toContain('countdownTimer({ target:')
        ->toContain('parts.days')
        ->toContain('parts.hours')
        ->not->toContain('parts.minutes')
        ->not->toContain('parts.seconds');
});

test('the largest unit on show carries the time above it', function () {
    $html = Blade::render('<x-util.countdown :until="now()->addDays(3)" units="hours,minutes" />');

    // Three days with days hidden is 72 hours, not a lost two and a bit.
    expect($html)->toContain('>72</span>');
});

test('the units are counted largest first however they were asked for', function () {
    $html = Blade::render('<x-util.countdown :until="now()->addHours(5)" units="minutes, days , hours" />');

    expect(strpos($html, 'parts.days'))->toBeLessThan(strpos($html, 'parts.hours'));
    expect(strpos($html, 'parts.hours'))->toBeLessThan(strpos($html, 'parts.minutes'));

    expect($html)
        ->toContain('>0</span>')     // days, unpadded as the first unit on show
        ->toContain('>05</span>');   // hours, padded behind it
});

test('the first paint already reads the right numbers', function () {
    $html = Blade::render('<x-util.countdown :until="now()->addDay()->addHours(2)->addMinutes(5)" />');

    expect($html)
        ->toContain('>1</span>')
        ->toContain('>02</span>')
        ->toContain('>05</span>');
});

test('a countdown that is already over never boots a timer', function () {
    $html = Blade::render('<x-util.countdown :until="now()->subMinute()" expire="reload" />');

    // Booting one would reload the page straight back into an expired countdown.
    expect($html)
        ->not->toContain('countdownTimer(')
        ->not->toContain('parts.days');
});

test('an expired countdown renders its message in place of the clock', function () {
    $html = Blade::render('<x-util.countdown :until="now()->subDay()" message="Sales have closed." />');

    expect($html)
        ->toContain('Sales have closed.')
        ->not->toContain('countdownTimer(');
});

test('a running countdown carries its message hidden until the clock crosses zero', function () {
    $html = Blade::render('<x-util.countdown :until="now()->addHour()" message="Sales have closed." />');

    expect($html)
        ->toContain('x-show="expired"')
        ->toContain('Sales have closed.');
});

test('a countdown that disappears hides itself and one that reloads does not', function () {
    $hides = Blade::render('<x-util.countdown :until="now()->addHour()" expire="hide" />');
    $reloads = Blade::render('<x-util.countdown :until="now()->addHour()" expire="reload" />');

    expect($hides)->toContain('x-show="! expired"');

    // The reloading one leaves its zeros on screen while the page comes back.
    expect($reloads)
        ->toContain("expire: 'reload'")
        ->not->toContain('x-show="! expired"');
});

test('each size renders at its own scale', function (string $size, string $number) {
    $html = Blade::render('<x-util.countdown :until="now()->addHour()" :size="$size" />', ['size' => $size]);

    expect($html)->toContain($number);
})->with([
    ['sm', 'text-lg'],
    ['md', 'text-2xl'],
    ['lg', 'text-4xl'],
]);

test('the boxed variant tiles its units and the plain one does not', function () {
    $boxed = Blade::render('<x-util.countdown :until="now()->addHour()" tone="lime" />');
    $plain = Blade::render('<x-util.countdown :until="now()->addHour()" tone="lime" variant="plain" />');

    expect($boxed)->toContain('bg-lime-100');
    expect($plain)
        ->not->toContain('bg-lime-100')
        ->toContain('text-lime-700');
});

test('the inline variant reads as one line of shorthand', function () {
    $html = Blade::render('<x-util.countdown :until="now()->addHour()" variant="inline" units="hours,minutes" />');

    expect($html)
        ->toContain('items-baseline')
        ->toContain('</span>h')
        ->toContain('</span>m')
        ->not->toContain('Minutes');
});

test('a typo in a prop is a loud failure rather than an unstyled clock', function (string $markup, string $complaint) {
    // Blade wraps anything thrown while rendering, so the message is what carries.
    expect(fn () => Blade::render($markup))->toThrow(ViewException::class, $complaint);
})->with([
    ['<x-util.countdown :until="now()->addHour()" size="huge" />', 'Invalid countdown size: huge'],
    ['<x-util.countdown :until="now()->addHour()" variant="circular" />', 'Invalid countdown variant: circular'],
    ['<x-util.countdown :until="now()->addHour()" expire="redirect" />', 'Invalid countdown expire action: redirect'],
    ['<x-util.countdown :until="now()->addHour()" units="weeks" />', 'A countdown shows days, hours, minutes or seconds'],
    ['<x-util.countdown :until="now()->addHour()" units="" />', 'A countdown shows days, hours, minutes or seconds'],
    ['<x-util.countdown :until="null" />', 'A countdown needs a date to count to'],
]);
