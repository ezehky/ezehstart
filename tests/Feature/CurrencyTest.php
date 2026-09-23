<?php

use App\Enums\ActivityActionEnum;
use App\Enums\StatusDefault;
use App\Enums\UserTypeEnum;
use App\Models\ActivityLog;
use App\Models\Currency;
use App\Services\CurrencyService;
use Database\Seeders\CurrencySeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(CurrencySeeder::class);

    $this->ngn = Currency::query()->where('code', 'NGN')->first();
    $this->usd = Currency::query()->where('code', 'USD')->first();
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// READING AMOUNTS

test('an install with no currency rows still prints naira', function () {
    Currency::query()->delete();
    app(CurrencyService::class)->flush();

    expect(kMoneyFormat(1500, decodeHtml: true))->toBe('₦1,500');
});

test('amounts are shown in the default currency to a guest', function () {
    expect(kMoneyFormat(1500, decodeHtml: true))->toBe('₦1,500');
});

test('a member who picked a currency reads amounts converted into it', function () {
    $member = userOfType(UserTypeEnum::USER, ['currency_id' => $this->usd->id]);

    $this->usd->update(['rate' => 0.001]);
    app(CurrencyService::class)->flush();

    expect(kMoneyFormat(150000, decodeHtml: true, user: $member))->toBe('$150')
        ->and(kMoneyFormat(150000, decodeHtml: true, convert: false, user: $member))->toBe('₦150,000');
});

test('a picked currency that is switched off falls back to the default', function () {
    $member = userOfType(UserTypeEnum::USER, ['currency_id' => $this->usd->id]);

    $this->usd->update(['status' => StatusDefault::INACTIVE]);
    app(CurrencyService::class)->flush();

    expect(kActiveCurrency($member)['code'])->toBe('NGN');
});

test('an explicit symbol means the amount is already in that currency', function () {
    $member = userOfType(UserTypeEnum::USER, ['currency_id' => $this->usd->id]);

    expect(kMoneyFormat(10, '€', true, user: $member))->toBe('€10');
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// CHANGING THE DEFAULT

test('making a currency the default rebases every other rate against it', function () {
    $this->usd->update(['rate' => 0.0005]);

    // The screen calls this for a signed-in administrator, who the log names.
    $this->actingAs(userOfType(UserTypeEnum::ADMIN));

    app(CurrencyService::class)->makeDefault($this->usd->fresh());

    expect($this->usd->fresh()->isDefault())->toBeTrue()
        ->and($this->usd->fresh()->rate)->toBe(1.0)
        ->and($this->ngn->fresh()->isDefault())->toBeFalse()
        ->and($this->ngn->fresh()->rate)->toBe(2000.0)
        ->and(ActivityLog::query()->where('activity_log_action', ActivityActionEnum::CURRENCY_DEFAULT)->exists())->toBeTrue();
});

test('an account that had picked the new default is put back on null', function () {
    $member = userOfType(UserTypeEnum::USER, ['currency_id' => $this->usd->id]);

    app(CurrencyService::class)->makeDefault($this->usd);

    expect($member->fresh()->currency_id)->toBeNull();
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// THE ADMIN SCREEN

test('an administrator adds a currency and the write is logged', function () {
    Livewire::actingAs(userOfType(UserTypeEnum::ADMIN))
        ->test('pages::admin.configs.currencies')
        ->call('create')
        ->set('name', 'Japanese Yen')
        ->set('code', 'jpy')
        ->set('symbol', '&#165;')
        ->set('rate', 0.1)
        ->call('save')
        ->assertHasNoErrors();

    expect(Currency::query()->where('code', 'JPY')->exists())->toBeTrue()
        ->and(ActivityLog::query()->where('activity_log_action', ActivityActionEnum::CURRENCY_CREATE)->exists())->toBeTrue();
});

test('the default keeps a rate of one and cannot be switched off from its form', function () {
    Livewire::actingAs(userOfType(UserTypeEnum::ADMIN))
        ->test('pages::admin.configs.currencies')
        ->call('edit', $this->ngn->id)
        ->set('rate', 42)
        ->set('status', false)
        ->call('save');

    expect($this->ngn->fresh()->rate)->toBe(1.0)
        ->and($this->ngn->fresh()->status)->toBe(StatusDefault::ACTIVE);
});

test('the default currency cannot be deleted', function () {
    Livewire::actingAs(userOfType(UserTypeEnum::ADMIN))
        ->test('pages::admin.configs.currencies')
        ->call('confirmDelete', $this->ngn->id)
        ->call('delete')
        ->assertHasErrors();

    expect($this->ngn->fresh())->not->toBeNull();
});

test('deleting a currency puts its users back on the default', function () {
    $member = userOfType(UserTypeEnum::USER, ['currency_id' => $this->usd->id]);

    Livewire::actingAs(userOfType(UserTypeEnum::ADMIN))
        ->test('pages::admin.configs.currencies')
        ->call('confirmDelete', $this->usd->id)
        ->call('delete')
        ->assertHasNoErrors();

    expect($member->fresh()->currency_id)->toBeNull();
});

test('a member cannot open the currency screen', function () {
    $this->actingAs(userOfType(UserTypeEnum::USER))
        ->get(route('admin.config.currencies'))
        ->assertNotFound();
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// THE MEMBER'S CHOICE

test('a member picks a currency on their settings screen', function () {
    $member = userOfType(UserTypeEnum::USER);

    Livewire::actingAs($member)
        ->test('pages::user.account.account-settings')
        ->set('currency_id', $this->usd->id)
        ->call('save')
        ->assertHasNoErrors();

    expect($member->fresh()->currency_id)->toBe($this->usd->id);
});

test('a member cannot pick a currency that is switched off', function () {
    $this->usd->update(['status' => StatusDefault::INACTIVE]);
    app(CurrencyService::class)->flush();

    Livewire::actingAs(userOfType(UserTypeEnum::USER))
        ->test('pages::user.account.account-settings')
        ->set('currency_id', $this->usd->id)
        ->call('save')
        ->assertHasErrors('currency_id');
});

test('the money field hands livewire a number and shows the default symbol', function () {
    $html = Blade::render('<x-form.money-field wire:model="amount" label="Amount" />');

    expect($html)->toContain('moneyInput')
        ->toContain('&#8358;')
        ->toContain('NGN')
        ->toContain("\$money(\$input, '.', ',', decimals)");
});
