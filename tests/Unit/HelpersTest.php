<?php

use App\Enums\StatusUser;
use App\Enums\UserTypeEnum;

test('kSlug maps the characters a plain slug would drop', function () {
    expect(kSlug('Design & Build'))->toBe('design-and-build')
        ->and(kSlug('mail@example'))->toBe('mail-at-example')
        ->and(kSlug('Hello World', '_'))->toBe('hello_world');
});

test('kBreakText turns a machine key into a label', function () {
    expect(kBreakText('user-role'))->toBe('User Role')
        ->and(kBreakText('user_role'))->toBe('User Role')
        ->and(kBreakText('user-role', lowercase: true))->toBe('user role')
        ->and(kBreakText(null))->toBeNull();
});

test('kTextCompare ignores case', function () {
    expect(kTextCompare('Active', 'active'))->toBeTrue()
        ->and(kTextCompare('Active', 'inactive'))->toBeFalse();
});

test('kPluralize agrees with its count', function () {
    expect(kPluralize('week', 1))->toBe('1 week')
        ->and(kPluralize('week', 3))->toBe('3 weeks');
});

test('kReferenceId carries its prefix and is unique', function () {
    $first = kReferenceId('WTH-');
    $second = kReferenceId('WTH-');

    expect($first)->toStartWith('WTH-')
        ->and($first)->not->toBe($second);
});

test('kStripDomainProtocols drops the scheme and any port', function () {
    expect(kStripDomainProtocols('https://example.test'))->toBe('example.test')
        ->and(kStripDomainProtocols('http://localhost:8000'))->toBe('localhost')
        ->and(kStripDomainProtocols('https://example.test', prefix: 'info'))->toBe('info@example.test');
});

test('kMoneyFormat returns a currency entity', function () {
    expect(kMoneyFormat(12500))->toContain('12,500')
        ->and(kMoneyFormat(12500, decodeHtml: true))->toContain('12,500');
});

test('enums built on WithEnumHelpers share a label and a select list', function () {
    expect(UserTypeEnum::ADMIN->label())->toBe('Admin')
        ->and(StatusUser::ACTIVE->boolValue())->toBeTrue()
        ->and(StatusUser::SUSPENDED->boolValue())->toBeFalse()
        ->and(UserTypeEnum::values())->toBe(['user', 'admin'])
        ->and(UserTypeEnum::forSelect())->toBe(['user' => 'User', 'admin' => 'Admin']);
});

test('a status enum answers one is-question per case', function () {
    expect(StatusUser::ACTIVE->isActive())->toBeTrue()
        ->and(StatusUser::ACTIVE->isSuspended())->toBeFalse()
        ->and(StatusUser::SUSPENDED->message())->toBe('Account is suspended.')
        ->and(StatusUser::ACTIVE->message())->toBeNull();
});
