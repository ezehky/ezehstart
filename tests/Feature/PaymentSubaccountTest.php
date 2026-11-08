<?php

use App\Enums\PaymentVendorEnum;
use App\Enums\StatusDefault;
use App\Enums\TransactionGroupEnum;
use App\Enums\TransactionTypeEnum;
use App\Enums\TransactionWalletEnum;
use App\Enums\UserTypeEnum;
use App\Models\ActivityLog;
use App\Models\PaymentSubaccount;
use App\Models\Transaction;
use App\Services\Payment\PaymentService;
use App\Services\Payment\PaymentSubaccountService;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    // A fake that misses must fail the test, never reach a real provider.
    Http::preventStrayRequests();

    config([
        'payments.default' => 'paystack',
        'payments.vendors.paystack.secret' => 'sk_test_paystack',
        'payments.vendors.stripe.secret' => 'sk_test_stripe',
        'payments.vendors.stripe.webhook_secret' => 'whsec_test',
        'payments.vendors.flutterwave.secret' => 'FLWSECK_TEST',
        'payments.vendors.flutterwave.webhook_hash' => 'flw-hash',
        'payments.vendors.monnify.api_key' => 'MK_TEST',
        'payments.vendors.monnify.secret' => 'monnify-secret',
        'payments.vendors.monnify.contract_code' => '1234567890',
        'payments.vendors.korapay.secret' => 'sk_test_kora',
    ]);

    $this->member = userOfType(UserTypeEnum::USER, ['email_verified_at' => now()]);
    $this->admin = userOfType(UserTypeEnum::ADMIN, ['email_verified_at' => now()]);
});

/**
 * A subaccount already on file, as though the vendor had accepted it.
 *
 * @param  array<string, mixed>  $attributes
 */
function splitSubaccount(PaymentVendorEnum $vendor = PaymentVendorEnum::PAYSTACK, array $attributes = []): PaymentSubaccount
{
    return PaymentSubaccount::query()->create([
        'vendor' => $vendor,
        'business_name' => "Ada's Bakery",
        'subaccount_code' => 'ACCT_ada',
        'bank_code' => '058',
        'bank_name' => 'GTBank',
        'account_number' => '0123456789',
        'account_name' => 'ADA BAKERY LTD',
        'contact_email' => 'accounts@ada.test',
        'currency' => strtoupper(kDefaultCurrency()['code']),
        'platform_percentage' => 10,
        ...$attributes,
    ]);
}

function fakePaystackBanks(): void
{
    Http::fake([
        'api.paystack.co/bank*' => Http::response([
            'status' => true,
            'data' => [
                ['name' => 'GTBank', 'code' => '058', 'active' => true],
                ['name' => 'Access Bank', 'code' => '044', 'active' => true],
            ],
        ]),
    ]);
}

function fakeMonnifyLogin(): void
{
    Http::fake([
        'sandbox.monnify.com/api/v1/auth/login' => Http::response([
            'requestSuccessful' => true,
            'responseBody' => ['accessToken' => 'monnify-token', 'expiresIn' => 3600],
        ]),
    ]);
}

// ||||||||||||||||||||||||||||||||||||||||||||||||
// WHICH VENDORS SPLIT

test('only Paystack, Flutterwave and Monnify can split a payment', function () {
    expect(PaymentVendorEnum::subaccountVendors())->toBe([
        PaymentVendorEnum::PAYSTACK,
        PaymentVendorEnum::FLUTTERWAVE,
        PaymentVendorEnum::MONNIFY,
    ]);

    // Stripe and Korapay have keys here and are offered at an ordinary
    // checkout, but never where a payment is split.
    expect(PaymentVendorEnum::checkoutOptions())->toContain(PaymentVendorEnum::STRIPE, PaymentVendorEnum::KORAPAY)
        ->and(PaymentVendorEnum::subaccountOptions())->not->toContain(PaymentVendorEnum::STRIPE)
        ->and(PaymentVendorEnum::subaccountOptions())->not->toContain(PaymentVendorEnum::KORAPAY)
        ->and(PaymentVendorEnum::subaccountOptions())->toContain(PaymentVendorEnum::PAYSTACK);
});

test('a payee is offered only the vendors it has an active subaccount on', function () {
    $paystack = splitSubaccount();
    $monnify = splitSubaccount(PaymentVendorEnum::MONNIFY, ['subaccount_code' => 'MFY_SUB_1', 'status' => StatusDefault::INACTIVE]);

    expect(app(PaymentSubaccountService::class)->checkoutOptionsFor([$paystack, $monnify]))->toBe([PaymentVendorEnum::PAYSTACK]);
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// CREATING

test('an administrator creates a Paystack subaccount and the vendor names the account holder', function () {
    fakePaystackBanks();
    Http::fake([
        'api.paystack.co/subaccount' => Http::response([
            'status' => true,
            'data' => ['id' => 55, 'subaccount_code' => 'ACCT_new', 'account_name' => 'ADA BAKERY LTD', 'settlement_bank' => 'GTBank'],
        ]),
    ]);

    Livewire::actingAs($this->admin)->test('pages::admin.subaccounts')
        ->call('create')
        ->assertSet('vendor', 'paystack')
        ->set('business_name', "Ada's Bakery")
        ->set('bank_code', '058')
        ->set('account_number', '0123456789')
        ->set('platform_percentage', 12.5)
        ->call('save')
        ->assertHasNoErrors();

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && str_ends_with($request->url(), '/subaccount')
        && $request['settlement_bank'] === '058'
        && (float) $request['percentage_charge'] === 12.5);

    $subaccount = PaymentSubaccount::query()->sole();

    expect($subaccount->subaccount_code)->toBe('ACCT_new')
        ->and($subaccount->vendor_record_id)->toBe('55')
        ->and($subaccount->account_name)->toBe('ADA BAKERY LTD')
        ->and($subaccount->bank_name)->toBe('GTBank')
        ->and($subaccount->subaccountPercentage())->toBe(87.5)
        ->and(ActivityLog::query()->where('description', 'like', '%payment subaccount%')->exists())->toBeTrue();
});

test('nothing is saved when the vendor refuses the subaccount', function () {
    fakePaystackBanks();
    Http::fake([
        'api.paystack.co/subaccount' => Http::response(['status' => false, 'message' => 'Account details are invalid'], 400),
    ]);

    Livewire::actingAs($this->admin)->test('pages::admin.subaccounts')
        ->call('create')
        ->set('business_name', "Ada's Bakery")
        ->set('bank_code', '058')
        ->set('account_number', '0123456789')
        ->call('save');

    expect(PaymentSubaccount::query()->exists())->toBeFalse();
});

test('a bank the vendor does not list is refused', function () {
    fakePaystackBanks();

    Livewire::actingAs($this->admin)->test('pages::admin.subaccounts')
        ->call('create')
        ->set('business_name', "Ada's Bakery")
        ->set('bank_code', '999')
        ->set('account_number', '0123456789')
        ->call('save')
        ->assertHasErrors(['bank_code']);
});

test('a provider that cannot split, or has no keys, cannot be picked', function () {
    config(['payments.vendors.monnify.api_key' => null]);
    fakePaystackBanks();

    foreach (['stripe', 'monnify'] as $vendor) {
        Livewire::actingAs($this->admin)->test('pages::admin.subaccounts')
            ->call('create')
            ->set('vendor', $vendor)
            ->set('business_name', "Ada's Bakery")
            ->set('account_number', '0123456789')
            ->call('save')
            ->assertHasErrors(['vendor']);
    }
});

test('Flutterwave asks for a phone and is sent its share as a fraction', function () {
    // The form opens on the default provider before Flutterwave is picked.
    fakePaystackBanks();
    Http::fake([
        'api.flutterwave.com/v3/banks/*' => Http::response(['status' => 'success', 'data' => [['id' => 1, 'code' => '044', 'name' => 'Access Bank']]]),
        'api.flutterwave.com/v3/subaccounts' => Http::response([
            'status' => 'success',
            'data' => ['id' => 2181, 'subaccount_id' => 'RS_ABC', 'full_name' => 'ADA BAKERY LTD', 'bank_name' => 'ACCESS BANK NIGERIA'],
        ]),
    ]);

    $page = Livewire::actingAs($this->admin)->test('pages::admin.subaccounts')
        ->call('create')
        ->set('vendor', 'flutterwave')
        ->set('business_name', "Ada's Bakery")
        ->set('bank_code', '044')
        ->set('account_number', '0690000031')
        ->set('platform_percentage', 10)
        ->call('save')
        ->assertHasErrors(['contact_phone']);

    $page->set('contact_phone', '+2348030000000')->call('save')->assertHasNoErrors();

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v3/subaccounts')
        && $request['split_type'] === 'percentage'
        && (float) $request['split_value'] === 0.1
        && $request['business_mobile'] === '+2348030000000'
        && $request['country'] === 'NG');

    expect(PaymentSubaccount::query()->sole())
        ->subaccount_code->toBe('RS_ABC')
        ->vendor_record_id->toBe('2181');
});

test('Monnify is sent a batch of one and the share the payee receives', function () {
    fakePaystackBanks();
    fakeMonnifyLogin();
    Http::fake([
        'sandbox.monnify.com/api/v1/banks' => Http::response(['requestSuccessful' => true, 'responseBody' => [['name' => 'GTBank', 'code' => '058']]]),
        'sandbox.monnify.com/api/v1/sub-accounts' => Http::response([
            'requestSuccessful' => true,
            'responseBody' => [['subAccountCode' => 'MFY_SUB_1', 'accountName' => 'ADA BAKERY LTD', 'bankName' => 'GTBank']],
        ]),
    ]);

    Livewire::actingAs($this->admin)->test('pages::admin.subaccounts')
        ->call('create')
        ->set('vendor', 'monnify')
        ->set('business_name', "Ada's Bakery")
        ->set('bank_code', '058')
        ->set('account_number', '0123456789')
        ->set('platform_percentage', 20)
        ->call('save')
        ->assertHasErrors(['contact_email'])
        ->set('contact_email', 'accounts@ada.test')
        ->call('save')
        ->assertHasNoErrors();

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && str_ends_with($request->url(), '/api/v1/sub-accounts')
        && array_is_list($request->data())
        && (float) $request[0]['defaultSplitPercentage'] === 80.0);

    expect(PaymentSubaccount::query()->sole()->subaccount_code)->toBe('MFY_SUB_1');
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// EDITING AND DELETING

test('switching a Paystack subaccount off is sent to Paystack', function () {
    $subaccount = splitSubaccount();
    fakePaystackBanks();
    Http::fake([
        'api.paystack.co/subaccount/ACCT_ada' => Http::response(['status' => true, 'data' => ['id' => 55, 'subaccount_code' => 'ACCT_ada', 'settlement_bank' => 'GTBank']]),
    ]);

    Livewire::actingAs($this->admin)->test('pages::admin.subaccounts')
        ->call('edit', $subaccount->id)
        ->set('active', false)
        ->call('save')
        ->assertHasNoErrors();

    Http::assertSent(fn ($request) => $request->method() === 'PUT' && $request['active'] === false);

    expect($subaccount->fresh()->status)->toBe(StatusDefault::INACTIVE);
});

test('a subaccount stays on the provider it was made on', function () {
    $subaccount = splitSubaccount();
    fakePaystackBanks();
    Http::fake(['api.paystack.co/subaccount/ACCT_ada' => Http::response(['status' => true, 'data' => ['subaccount_code' => 'ACCT_ada']])]);

    Livewire::actingAs($this->admin)->test('pages::admin.subaccounts')
        ->call('edit', $subaccount->id)
        ->set('vendor', 'monnify')
        ->set('bank_code', '058')
        ->set('business_name', 'Ada Bakes')
        ->call('save')
        ->assertHasNoErrors();

    expect($subaccount->fresh())
        ->vendor->toBe(PaymentVendorEnum::PAYSTACK)
        ->business_name->toBe('Ada Bakes');
});

test('a subaccount payments were split with cannot be deleted', function () {
    $used = splitSubaccount();
    $unused = splitSubaccount(attributes: ['subaccount_code' => 'ACCT_other']);

    Http::fake([
        'api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/x', 'reference' => 'x']]),
    ]);

    app(PaymentService::class)->checkout($this->member, PaymentVendorEnum::PAYSTACK, 5000, 'Order 1', TransactionGroupEnum::PURCHASE, TransactionTypeEnum::DIRECT, TransactionWalletEnum::NONE, subaccount: $used);

    Livewire::actingAs($this->admin)->test('pages::admin.subaccounts')->call('confirmDelete', $used->id)->call('delete');
    Livewire::actingAs($this->admin)->test('pages::admin.subaccounts')->call('confirmDelete', $unused->id)->call('delete')->assertHasNoErrors();

    expect($used->fresh())->not->toBeNull()
        ->and($unused->fresh())->toBeNull();
});

test('the subaccounts screen is behind its own gate', function () {
    $clerk = adminWithRoles(roleWithGates('Clerk', ['finance.payments' => 'full']));

    $this->actingAs($clerk)->get(route('admin.subaccounts'))->assertNotFound();
    $this->actingAs($this->admin)->get(route('admin.subaccounts'))->assertOk();
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// SPLITTING A CHECKOUT

test('a Paystack checkout split with a subaccount names it and remembers it', function () {
    $subaccount = splitSubaccount();

    Http::fake([
        'api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/x', 'reference' => 'x']]),
    ]);

    $transaction = app(PaymentService::class)->checkout(
        $this->member,
        PaymentVendorEnum::PAYSTACK,
        5000,
        'Order 1',
        TransactionGroupEnum::PURCHASE,
        TransactionTypeEnum::DIRECT,
        TransactionWalletEnum::NONE,
        subaccount: $subaccount,
    );

    expect($transaction)->toBeInstanceOf(Transaction::class)
        ->and($transaction->gateway->fresh()->payment_subaccount_id)->toBe($subaccount->id);

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/transaction/initialize') && $request['subaccount'] === 'ACCT_ada');
});

test('a checkout without a subaccount carries no split', function () {
    Http::fake([
        'api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/x', 'reference' => 'x']]),
    ]);

    app(PaymentService::class)->checkout($this->member, PaymentVendorEnum::PAYSTACK, 5000, 'Order 1', TransactionGroupEnum::PURCHASE, TransactionTypeEnum::DIRECT, TransactionWalletEnum::NONE);

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/transaction/initialize') && ! isset($request['subaccount']));
});

test('Flutterwave and Monnify checkouts carry their own split fields', function () {
    fakeMonnifyLogin();
    Http::fake([
        'api.flutterwave.com/v3/payments' => Http::response(['status' => 'success', 'data' => ['link' => 'https://checkout.flutterwave.com/x']]),
        'sandbox.monnify.com/api/v1/merchant/transactions/init-transaction' => Http::response([
            'requestSuccessful' => true,
            'responseBody' => ['checkoutUrl' => 'https://sandbox.sdk.monnify.com/x', 'transactionReference' => 'MNFY|1'],
        ]),
    ]);

    $flutterwave = splitSubaccount(PaymentVendorEnum::FLUTTERWAVE, ['subaccount_code' => 'RS_ABC']);
    $monnify = splitSubaccount(PaymentVendorEnum::MONNIFY, ['subaccount_code' => 'MFY_SUB_1', 'platform_percentage' => 25]);

    foreach ([$flutterwave, $monnify] as $subaccount) {
        $result = app(PaymentService::class)->checkout($this->member, $subaccount->vendor, 5000, 'Order 1', TransactionGroupEnum::PURCHASE, TransactionTypeEnum::DIRECT, TransactionWalletEnum::NONE, subaccount: $subaccount);

        expect($result)->toBeInstanceOf(Transaction::class);
    }

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v3/payments') && $request['subaccounts'] === [['id' => 'RS_ABC']]);
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/init-transaction')
        && $request['incomeSplitConfig'][0]['subAccountCode'] === 'MFY_SUB_1'
        && (float) $request['incomeSplitConfig'][0]['splitPercentage'] === 75.0);
});

test('a split checkout is refused before anything is written when the vendor cannot take it', function (Closure $case, string $message) {
    [$vendor, $subaccount, $wallet] = $case();

    $result = app(PaymentService::class)->checkout(
        $this->member,
        $vendor,
        5000,
        'Order 1',
        TransactionGroupEnum::PURCHASE,
        TransactionTypeEnum::DIRECT,
        $wallet,
        subaccount: $subaccount,
    );

    expect($result)->toBeString()->toContain($message)
        ->and(Transaction::query()->exists())->toBeFalse();

    Http::assertNothingSent();
})->with([
    'a vendor that cannot split' => [fn () => [PaymentVendorEnum::STRIPE, splitSubaccount(), TransactionWalletEnum::NONE], 'cannot split'],
    'another vendor\'s subaccount' => [fn () => [PaymentVendorEnum::FLUTTERWAVE, splitSubaccount(), TransactionWalletEnum::NONE], 'has to go through Paystack'],
    'a switched-off subaccount' => [fn () => [PaymentVendorEnum::PAYSTACK, splitSubaccount(attributes: ['status' => StatusDefault::INACTIVE]), TransactionWalletEnum::NONE], 'switched off'],
    'another currency' => [fn () => [PaymentVendorEnum::PAYSTACK, splitSubaccount(attributes: ['currency' => 'XYZ']), TransactionWalletEnum::NONE], 'takes XYZ'],
    'a wallet deposit' => [fn () => [PaymentVendorEnum::PAYSTACK, splitSubaccount(), TransactionWalletEnum::BALANCE], 'wallet deposit'],
]);
