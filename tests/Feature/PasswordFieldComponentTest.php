<?php

use Illuminate\Support\Facades\Blade;

/**
 * The strength note is a tooltip that has to survive typing.
 *
 * Flux's own tooltip closes as soon as focus moves inside the field, which is the
 * one moment the rule is worth reading, so the component draws its own. These
 * tests assert the markup that keeps it open rather than the behaviour, which is
 * Alpine's to run.
 */
test('a field with no note renders the bare input', function () {
    $html = Blade::render('<x-form.password label="Current password" />');

    expect($html)->toContain('type="password"')
        ->and($html)->not->toContain('role="note"');
});

test('a field with a note renders the tooltip', function () {
    $html = Blade::render('<x-form.password label="New password" :note="$note" />', [
        'note' => 'Password must be at least 5 characters.',
    ]);

    expect($html)->toContain('role="note"')
        ->and($html)->toContain('Password must be at least 5 characters.');
});

test('the note is held open by focus as well as by hover', function () {
    $html = Blade::render('<x-form.password :note="$note" />', ['note' => 'A rule.']);

    // Two independent flags: the pointer leaving must not close a note the
    // person is still typing under.
    expect($html)->toContain('x-on:focusin')
        ->and($html)->toContain('x-on:mouseenter')
        ->and($html)->toContain('focused || this.hovered');
});

test('focus moving to the reveal toggle does not close the note', function () {
    $html = Blade::render('<x-form.password :note="$note" />', ['note' => 'A rule.']);

    // The eye button lives inside the wrapper, so focusout fires as focus moves
    // onto it. Asking whether the new target is still inside is what stops the
    // note flickering shut every time somebody reveals what they typed.
    expect($html)->toContain('$el.contains($event.relatedTarget)');
});
