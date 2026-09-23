<?php

use App\Enums\UserTypeEnum;
use App\Rules\PhoneRule;
use Database\Seeders\CountrySeeder;
use Livewire\Livewire;

test('a national number and its dialling code join into one international number', function () {
    expect(kPhoneInternational('0801 234 5678', '+234'))->toBe('+2348012345678')
        ->and(kPhoneInternational('+44 20 7946 0958', '+234'))->toBe('+442079460958')
        ->and(kPhoneInternational('', '+234'))->toBeNull()
        ->and(kPhoneInternational('08012345678'))->toBe('08012345678');
});

test('the phone rule refuses what is plainly not a number', function (string $value, bool $passes) {
    $validator = Validator::make(['phone' => $value], ['phone' => [new PhoneRule('+234')]]);

    expect($validator->passes())->toBe($passes);
})->with([
    'a nigerian mobile' => ['08012345678', true],
    'with spaces' => ['0801 234 5678', true],
    'letters' => ['call me', false],
    'a fragment' => ['12', false],
    'too long' => ['0801234567890123456', false],
]);

test('an administrator saves a member phone in its three parts', function () {
    $this->seed(CountrySeeder::class);

    $member = userOfType(UserTypeEnum::USER);

    Livewire::actingAs(userOfType(UserTypeEnum::ADMIN))
        ->test('pages::admin.users.user-view', ['user' => $member])
        ->call('editAccount')
        ->set('phone_number', '08012345678')
        ->set('phone_dial_code', '+234')
        ->set('phone_iso2', 'NG')
        ->call('save')
        ->assertHasNoErrors();

    $member->refresh();

    expect($member->phone_number)->toBe('08012345678')
        ->and($member->phone_dial_code)->toBe('+234')
        ->and($member->phone_iso2)->toBe('NG')
        ->and($member->phoneInternational())->toBe('+2348012345678');
});

test('a number without a country is refused', function () {
    $member = userOfType(UserTypeEnum::USER);

    Livewire::actingAs(userOfType(UserTypeEnum::ADMIN))
        ->test('pages::admin.users.user-view', ['user' => $member])
        ->call('editAccount')
        ->set('phone_number', '08012345678')
        ->set('phone_dial_code', null)
        ->set('phone_iso2', null)
        ->call('save')
        ->assertHasErrors(['phone_dial_code', 'phone_iso2']);
});

test('the phone field renders the picker bound to all three properties', function () {
    $html = Blade::render('<x-form.phone-field wire:model="phone_number" dial-code="phone_dial_code" iso2="phone_iso2" />');

    expect($html)->toContain('phoneInput')
        ->toContain("\$wire.\$entangle('phone_number')")
        ->toContain("\$wire.\$entangle('phone_dial_code')")
        ->toContain('wire:ignore');
});
