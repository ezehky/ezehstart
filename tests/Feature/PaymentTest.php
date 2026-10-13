<?php

use App\Enums\NotificationTopicEnum;
use App\Enums\PaymentVendorEnum;
use App\Enums\PaymentWebhookTopicEnum;
use App\Enums\StatusPaymentWebhook;
use App\Enums\StatusTransaction;
use App\Enums\TransactionViaEnum;
use App\Enums\UserTypeEnum;
use App\Events\PaymentFailed;
use App\Events\PaymentNeedsReview;
use App\Events\PaymentSucceeded;
use App\Models\PaymentTrace;
use App\Models\PaymentWebhook;
use App\Models\Transaction;
use App\Services\Payment\Gateways\KorapayGatewayService;
use App\Services\Payment\Gateways\OpayGatewayService;
use App\Services\Payment\PaymentService;
use App\Services\TransactionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
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
        'payments.vendors.opay.merchant_id' => '256000000000000',
        'payments.vendors.opay.public' => 'OPAYPUB',
        'payments.vendors.opay.secret' => 'OPAYPRV',
        'payments.vendors.korapay.secret' => 'sk_test_kora',
    ]);

    $this->member = userOfType(UserTypeEnum::USER, ['email_verified_at' => now()]);
});

/**
 * A Paystack deposit opened through the real checkout, with Paystack faked.
 */
function openPaystackDeposit($user, float $amount = 5000): Transaction
{
    Http::fake([
        'api.paystack.co/transaction/initialize' => Http::response([
            'status' => true,
            'data' => ['authorization_url' => 'https://checkout.paystack.com/abc', 'access_code' => 'abc', 'reference' => 'ignored'],
        ]),
    ]);

    return app(PaymentService::class)->checkout($user, PaymentVendorEnum::PAYSTACK, $amount, 'Wallet top-up');
}

/**
 * What Paystack's verify endpoint answers for a reference.
 */
function fakePaystackVerify(string $status, int $amountInKobo, string $currency = 'NGN'): void
{
    Http::fake([
        'api.paystack.co/transaction/verify/*' => Http::response([
            'status' => true,
            'data' => [
                'id' => 99001,
                'status' => $status,
                'amount' => $amountInKobo,
                'currency' => $currency,
                'channel' => 'card',
                'paid_at' => now()->toIso8601String(),
                'gateway_response' => 'Approved',
                'authorization' => ['authorization_code' => 'AUTH_secret', 'last4' => '4081'],
            ],
        ]),
    ]);
}

/**
 * Post a raw body to the webhook route, the way a vendor does — the signature
 * has to be over the exact bytes, so the body is never re-encoded by the test.
 *
 * @param  array<string, string>  $headers
 */
function postWebhook($test, string $vendor, string $body, array $headers = [])
{
    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];

    foreach ($headers as $name => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }

    return $test->call('POST', route('payments.webhook', ['vendor' => $vendor]), [], [], [], $server, $body);
}

// ||||||||||||||||||||||||||||||||||||||||||||||||
// VENDORS

test('a vendor is only offered once its keys are filled in', function () {
    config(['payments.vendors.paypal.client_id' => null]);

    expect(PaymentVendorEnum::PAYSTACK->isAvailable())->toBeTrue()
        ->and(PaymentVendorEnum::PAYPAL->isAvailable())->toBeFalse()
        ->and(PaymentVendorEnum::checkoutOptions()[0])->toBe(PaymentVendorEnum::PAYSTACK);

    config(['payments.vendors.paystack.enabled' => false]);

    expect(PaymentVendorEnum::PAYSTACK->isAvailable())->toBeFalse();
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// CHECKOUT

test('a checkout writes a processing transaction and opens it with the vendor', function () {
    $transaction = openPaystackDeposit($this->member, 5000);

    expect($transaction)->toBeInstanceOf(Transaction::class)
        ->and($transaction->status)->toBe(StatusTransaction::PROCESSING)
        ->and($transaction->via)->toBe(TransactionViaEnum::PAYMENT_GATEWAY)
        ->and($transaction->gateway->vendor)->toBe(PaymentVendorEnum::PAYSTACK)
        ->and($transaction->gateway->gateway_url)->toBe('https://checkout.paystack.com/abc')
        ->and($transaction->gateway->expires_at)->not->toBeNull();

    // Paystack counts in kobo, and is handed our reference as its own.
    Http::assertSent(fn ($request) => $request['amount'] === 500000
        && $request['reference'] === $transaction->reference
        && str_contains($request['callback_url'], $transaction->reference));

    // Nothing has moved yet.
    expect(app(TransactionService::class)->balanceFor($this->member))->toBe(0.0);
});

test('a checkout the vendor refuses is closed as failed', function () {
    Event::fake([PaymentFailed::class]);

    Http::fake([
        'api.paystack.co/*' => Http::response(['status' => false, 'message' => 'Invalid key'], 401),
    ]);

    $result = app(PaymentService::class)->checkout($this->member, PaymentVendorEnum::PAYSTACK, 100, 'Top-up');

    expect($result)->toBe('Invalid key')
        ->and(Transaction::query()->sole()->status)->toBe(StatusTransaction::FAILED);

    Event::assertDispatched(PaymentFailed::class);
});

test('a vendor without keys cannot be checked out with', function () {
    $result = app(PaymentService::class)->checkout($this->member, PaymentVendorEnum::PAYPAL, 100, 'Top-up');

    expect($result)->toBeString()
        ->and(Transaction::query()->count())->toBe(0);
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// VERIFICATION

test('a verified payment confirms the row and credits the balance once', function () {
    Event::fake([PaymentSucceeded::class]);

    $transaction = openPaystackDeposit($this->member, 5000);
    fakePaystackVerify('success', 500000);

    $service = app(PaymentService::class);
    $first = $service->verify($transaction);
    $second = $service->verify($transaction->fresh());

    expect($first->status)->toBe(StatusTransaction::CONFIRMED)
        ->and($second->status)->toBe(StatusTransaction::CONFIRMED)
        ->and($first->gateway->channel)->toBe('card')
        ->and($first->gateway->gateway_request_id)->toBe('99001')
        ->and(app(TransactionService::class)->balanceFor($this->member))->toBe(5000.0);

    Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
});

test('the vendor answer is kept on the transaction without card or credential fields', function () {
    $transaction = openPaystackDeposit($this->member, 100);
    fakePaystackVerify('success', 10000);

    app(PaymentService::class)->verify($transaction);

    $stored = $transaction->fresh()->meta->content['gateway'];

    expect($stored['authorization'])->toBe('[redacted]')
        ->and($stored['status'])->toBe('success');
});

test('a short payment is held for review instead of credited', function () {
    Event::fake([PaymentNeedsReview::class, PaymentSucceeded::class]);

    $transaction = openPaystackDeposit($this->member, 5000);
    fakePaystackVerify('success', 100000);

    $result = app(PaymentService::class)->verify($transaction);

    expect($result->status)->toBe(StatusTransaction::PROCESSING)
        ->and($result->gateway->failure_reason)->toContain('against')
        ->and(app(TransactionService::class)->balanceFor($this->member))->toBe(0.0);

    Event::assertDispatched(PaymentNeedsReview::class);
    Event::assertNotDispatched(PaymentSucceeded::class);
});

test('a payment in another currency is held for review', function () {
    Event::fake([PaymentNeedsReview::class]);

    $transaction = openPaystackDeposit($this->member, 50);
    fakePaystackVerify('success', 5000, 'USD');

    $result = app(PaymentService::class)->verify($transaction);

    expect($result->status)->toBe(StatusTransaction::PROCESSING)
        ->and($result->gateway->failure_reason)->toContain('USD');

    Event::assertDispatched(PaymentNeedsReview::class);
});

test('a failed payment is settled as failed', function () {
    Event::fake([PaymentFailed::class]);

    $transaction = openPaystackDeposit($this->member, 100);
    fakePaystackVerify('failed', 10000);

    expect(app(PaymentService::class)->verify($transaction)->status)->toBe(StatusTransaction::FAILED);

    Event::assertDispatched(PaymentFailed::class);
});

test('money that lands on a closed row is flagged, not credited', function () {
    Event::fake([PaymentNeedsReview::class]);

    $transaction = openPaystackDeposit($this->member, 100);
    app(TransactionService::class)->settle($transaction, StatusTransaction::CANCELLED);

    fakePaystackVerify('success', 10000);

    $result = app(PaymentService::class)->verify($transaction->fresh());

    expect($result->status)->toBe(StatusTransaction::CONFIRMED_BUT_NOT_LOGGED)
        ->and(app(TransactionService::class)->balanceFor($this->member))->toBe(0.0);

    Event::assertDispatched(PaymentNeedsReview::class);
});

test('a confirmed payment notifies the account holder', function () {
    $transaction = openPaystackDeposit($this->member, 100);
    fakePaystackVerify('success', 10000);

    app(PaymentService::class)->verify($transaction);

    expect($this->member->notifications()->first()?->data['title'] ?? null)
        ->toBe(NotificationTopicEnum::PAYMENT_CONFIRMED->value);
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// CALLBACK

test('the callback asks the vendor and reports the result to the owner', function () {
    $transaction = openPaystackDeposit($this->member, 100);
    fakePaystackVerify('success', 10000);

    $this->actingAs($this->member)
        ->get(route('payments.callback', ['vendor' => 'paystack', 'reference' => $transaction->reference, 'status' => 'failed']))
        ->assertRedirect(route('user.transactions'))
        ->assertSessionHas('status');

    // The query string said failed; the vendor said paid, and the vendor wins.
    expect($transaction->fresh()->status)->toBe(StatusTransaction::CONFIRMED);
});

test('the callback says nothing about somebody else\'s payment', function () {
    $transaction = openPaystackDeposit($this->member, 100);
    fakePaystackVerify('success', 10000);

    $other = userOfType(UserTypeEnum::USER);

    $this->actingAs($other)
        ->get(route('payments.callback', ['vendor' => 'paystack', 'reference' => $transaction->reference]))
        ->assertRedirect(route('user.transactions'))
        ->assertSessionMissing('status')
        ->assertSessionMissing('error');
});

test('the callback refuses a reference under the wrong vendor', function () {
    $transaction = openPaystackDeposit($this->member, 100);

    $this->get(route('payments.callback', ['vendor' => 'stripe', 'reference' => $transaction->reference]))
        ->assertNotFound();
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// WEBHOOKS

test('a signed Paystack webhook is stored, worked and settles the payment', function () {
    $transaction = openPaystackDeposit($this->member, 100);
    fakePaystackVerify('success', 10000);

    $body = json_encode(['event' => 'charge.success', 'data' => ['reference' => $transaction->reference, 'status' => 'success']]);

    postWebhook($this, 'paystack', $body, ['x-paystack-signature' => hash_hmac('sha512', $body, 'sk_test_paystack')])
        ->assertOk()
        ->assertJson(['status' => 'queued']);

    $webhook = PaymentWebhook::query()->sole();

    expect($webhook->status)->toBe(StatusPaymentWebhook::PROCESSED)
        ->and($webhook->topic)->toBe(PaymentWebhookTopicEnum::CHARGE)
        ->and($webhook->reference)->toBe($transaction->reference)
        ->and($transaction->fresh()->status)->toBe(StatusTransaction::CONFIRMED);
});

test('a webhook with a bad signature is refused before anything is stored', function () {
    $body = json_encode(['event' => 'charge.success', 'data' => ['reference' => 'TXN-NOPE']]);

    postWebhook($this, 'paystack', $body, ['x-paystack-signature' => 'forged'])->assertUnauthorized();
    postWebhook($this, 'paystack', $body)->assertUnauthorized();

    expect(PaymentWebhook::query()->count())->toBe(0);
});

test('a retried delivery is acknowledged and dropped', function () {
    $body = json_encode(['event' => 'charge.success', 'data' => ['reference' => 'TXN-UNKNOWN']]);
    $signature = hash_hmac('sha512', $body, 'sk_test_paystack');

    postWebhook($this, 'paystack', $body, ['x-paystack-signature' => $signature])->assertJson(['status' => 'queued']);
    postWebhook($this, 'paystack', $body, ['x-paystack-signature' => $signature])->assertJson(['status' => 'duplicate']);

    expect(PaymentWebhook::query()->count())->toBe(1)
        ->and(PaymentWebhook::query()->sole()->status)->toBe(StatusPaymentWebhook::IGNORED);
});

test('a webhook for an unconfigured vendor is a 404', function () {
    postWebhook($this, 'paypal', '{"id":"WH-1"}')->assertNotFound();
    postWebhook($this, 'nobody', '{"id":"WH-1"}')->assertNotFound();
});

test('the signature switch is ignored outside local and testing', function () {
    config(['payments.webhooks.verify_signature' => false]);

    $body = json_encode(['event' => 'charge.success', 'data' => ['reference' => 'TXN-X']]);

    postWebhook($this, 'paystack', $body)->assertOk();

    app()['env'] = 'production';

    postWebhook($this, 'paystack', json_encode(['event' => 'charge.success', 'data' => ['reference' => 'TXN-Y']]))
        ->assertUnauthorized();
});

test('a failed webhook can be retried', function () {
    $webhook = PaymentWebhook::query()->create([
        'vendor' => PaymentVendorEnum::PAYSTACK,
        'event_key' => 'abc',
        'topic' => PaymentWebhookTopicEnum::CHARGE,
        'reference' => 'TXN-GONE',
        'payload' => [],
        'status' => StatusPaymentWebhook::FAILED,
    ]);

    $this->artisan('payments:retry-webhooks')->assertSuccessful();

    expect($webhook->fresh()->status)->toBe(StatusPaymentWebhook::IGNORED);
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// SIGNATURES — one per vendor, over the scheme each one actually uses

test('Stripe signatures are checked over the timestamp and refused when stale', function () {
    $body = json_encode(['id' => 'evt_1', 'type' => 'checkout.session.completed', 'data' => ['object' => ['client_reference_id' => 'TXN-1']]]);
    $gateway = PaymentVendorEnum::STRIPE->gateway();

    $sign = fn (int $time) => "t={$time},v1=".hash_hmac('sha256', "{$time}.{$body}", 'whsec_test');
    $request = fn (string $header) => tap(Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body),
        fn ($request) => $request->headers->set('stripe-signature', $header));

    expect($gateway->verifyWebhook($request($sign(now()->getTimestamp()))))->toBeTrue()
        ->and($gateway->verifyWebhook($request($sign(now()->subMinutes(10)->getTimestamp()))))->toBeFalse()
        ->and($gateway->parseWebhook($request($sign(now()->getTimestamp()))))->toMatchArray([
            'event_key' => 'evt_1',
            'topic' => PaymentWebhookTopicEnum::CHARGE,
            'reference' => 'TXN-1',
        ]);
});

test('Flutterwave webhooks carry the dashboard hash', function () {
    $body = json_encode(['event' => 'charge.completed', 'data' => ['tx_ref' => 'TXN-2']]);

    postWebhook($this, 'flutterwave', $body, ['verif-hash' => 'wrong'])->assertUnauthorized();
    postWebhook($this, 'flutterwave', $body, ['verif-hash' => 'flw-hash'])->assertOk();

    expect(PaymentWebhook::query()->sole()->reference)->toBe('TXN-2');
});

test('Monnify webhooks are an HMAC-SHA512 of the body', function () {
    $body = json_encode(['eventType' => 'SUCCESSFUL_TRANSACTION', 'eventData' => ['paymentReference' => 'TXN-3']]);

    postWebhook($this, 'monnify', $body, ['monnify-signature' => hash_hmac('sha512', $body, 'monnify-secret')])->assertOk();

    expect(PaymentWebhook::query()->sole()->topic)->toBe(PaymentWebhookTopicEnum::CHARGE);
});

test('OPay callbacks are an HMAC-SHA3-512 over its fixed template', function () {
    $payload = [
        'amount' => '10000', 'channel' => 'Web', 'country' => 'NG', 'currency' => 'NGN',
        'reference' => 'TXN-4', 'refunded' => false, 'status' => 'SUCCESS',
        'timestamp' => '2026-10-10T10:00:00Z', 'token' => '220101', 'transactionId' => '2201',
    ];

    $signed = '{Amount:"10000",Currency:"NGN",Reference:"TXN-4",Refunded:f,Status:"SUCCESS",Timestamp:"2026-10-10T10:00:00Z",Token:"220101",TransactionID:"2201"}';

    $valid = json_encode(['payload' => $payload, 'sha512' => hash_hmac('sha3-512', $signed, 'OPAYPRV'), 'type' => 'transaction-status']);
    $sha2 = json_encode(['payload' => $payload, 'sha512' => hash_hmac('sha512', $signed, 'OPAYPRV'), 'type' => 'transaction-status']);

    $gateway = app(OpayGatewayService::class);

    expect($gateway->verifyWebhook(Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $valid)))->toBeTrue()
        ->and($gateway->verifyWebhook(Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $sha2)))->toBeFalse();
});

test('Korapay webhooks are signed over the data object as it was sent', function () {
    // Written the way JavaScript serialises it: unescaped slashes, a whole
    // number with no decimal point. Re-encoding in PHP would give other bytes.
    $data = '{"reference":"TXN-5","amount":100,"currency":"NGN","status":"success","payment_method":"bank/transfer"}';
    $body = '{"event":"charge.success","data":'.$data.'}';

    $request = Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body);
    $request->headers->set('x-korapay-signature', hash_hmac('sha256', $data, 'sk_test_kora'));

    expect(app(KorapayGatewayService::class)->verifyWebhook($request))->toBeTrue();

    $request->headers->set('x-korapay-signature', hash_hmac('sha256', $data, 'another-key'));

    expect(app(KorapayGatewayService::class)->verifyWebhook($request))->toBeFalse();
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// OTHER VENDORS' CHECKOUT AND VERIFICATION

test('Stripe opens a checkout session and verifies against it', function () {
    Http::fake([
        'api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_test_1', 'url' => 'https://checkout.stripe.com/c/cs_test_1']),
        'api.stripe.com/v1/checkout/sessions/cs_test_1' => Http::response([
            'id' => 'cs_test_1', 'status' => 'complete', 'payment_status' => 'paid',
            'amount_total' => 2500, 'currency' => 'ngn', 'payment_intent' => 'pi_1', 'payment_method_types' => ['card'],
        ]),
    ]);

    $transaction = app(PaymentService::class)->checkout($this->member, PaymentVendorEnum::STRIPE, 25, 'Top-up');

    expect($transaction->gateway->gateway_id)->toBe('cs_test_1');

    $result = app(PaymentService::class)->verify($transaction);

    expect($result->status)->toBe(StatusTransaction::CONFIRMED)
        ->and($result->gateway->gateway_id)->toBe('cs_test_1')
        ->and($result->gateway->gateway_request_id)->toBe('pi_1');

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/checkout/sessions')
        && $request['line_items'][0]['price_data']['unit_amount'] === 2500
        && $request['client_reference_id'] === $transaction->reference);
});

test('a PayPal order the customer approved is captured during verification', function () {
    config([
        'payments.vendors.paypal.client_id' => 'client',
        'payments.vendors.paypal.secret' => 'secret',
        'payments.vendors.paypal.webhook_id' => 'WH-ID',
    ]);

    Http::fake([
        'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'A21', 'expires_in' => 3600]),
        'api-m.sandbox.paypal.com/v2/checkout/orders' => Http::response([
            'id' => 'ORDER-1',
            'links' => [['rel' => 'payer-action', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=ORDER-1']],
        ]),
        'api-m.sandbox.paypal.com/v2/checkout/orders/ORDER-1' => Http::response(['id' => 'ORDER-1', 'status' => 'APPROVED']),
        'api-m.sandbox.paypal.com/v2/checkout/orders/ORDER-1/capture' => Http::response([
            'id' => 'ORDER-1',
            'status' => 'COMPLETED',
            'purchase_units' => [['payments' => ['captures' => [[
                'id' => 'CAPTURE-1', 'status' => 'COMPLETED', 'amount' => ['value' => '25.00', 'currency_code' => 'NGN'],
            ]]]]],
        ]),
    ]);

    $transaction = app(PaymentService::class)->checkout($this->member, PaymentVendorEnum::PAYPAL, 25, 'Top-up');
    $result = app(PaymentService::class)->verify($transaction);

    expect($result->status)->toBe(StatusTransaction::CONFIRMED)
        ->and($result->gateway->gateway_request_id)->toBe('CAPTURE-1');

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/capture')
        && $request->header('PayPal-Request-Id')[0] === "capture-{$transaction->reference}");
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// RECONCILE

test('the reconcile sweep settles paid checkouts and closes long-abandoned ones', function () {
    $paid = openPaystackDeposit($this->member, 100);
    $abandoned = openPaystackDeposit($this->member, 200);

    Transaction::query()->whereKey([$paid->id, $abandoned->id])->update(['created_at' => now()->subDays(3)]);
    $abandoned->gateway->update(['expires_at' => now()->subDays(2)]);

    Http::fake([
        "api.paystack.co/transaction/verify/{$paid->reference}" => Http::response(['status' => true, 'data' => ['status' => 'success', 'amount' => 10000, 'currency' => 'NGN']]),
        "api.paystack.co/transaction/verify/{$abandoned->reference}" => Http::response(['status' => true, 'data' => ['status' => 'abandoned', 'amount' => 20000, 'currency' => 'NGN']]),
    ]);

    $this->artisan('payments:reconcile')->assertSuccessful();

    expect($paid->fresh()->status)->toBe(StatusTransaction::CONFIRMED)
        ->and($abandoned->fresh()->status)->toBe(StatusTransaction::CANCELLED);
});

test('the reconcile sweep leaves a fresh checkout alone', function () {
    $fresh = openPaystackDeposit($this->member, 100);

    $this->artisan('payments:reconcile')->assertSuccessful();

    expect($fresh->fresh()->status)->toBe(StatusTransaction::PROCESSING);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/verify/'));
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// TRACING

test('nothing is traced while tracing is off', function () {
    openPaystackDeposit($this->member, 100);

    expect(PaymentTrace::query()->count())->toBe(0);
});

test('a trace keeps the call and loses the credentials', function () {
    config(['payments.tracing.enabled' => true]);

    $transaction = openPaystackDeposit($this->member, 100);
    fakePaystackVerify('success', 10000);
    app(PaymentService::class)->verify($transaction);

    $traces = PaymentTrace::query()->where('transaction_id', $transaction->id)->get();

    expect($traces)->toHaveCount(2)
        ->and($traces->pluck('trace_step')->map->value->all())->toBe(['initialize', 'verify'])
        ->and(json_encode($traces->pluck('context')))->not->toContain('AUTH_secret')
        ->and(json_encode($traces->pluck('context')))->not->toContain('sk_test_paystack');
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// MEMBER SCREEN

test('a member adding funds is sent to the vendor checkout', function () {
    Http::fake([
        'api.paystack.co/transaction/initialize' => Http::response([
            'status' => true,
            'data' => ['authorization_url' => 'https://checkout.paystack.com/xyz', 'reference' => 'x'],
        ]),
    ]);

    Livewire::actingAs($this->member)
        ->test('pages::user.transactions')
        ->call('openDeposit')
        ->assertSet('deposit_vendor', 'paystack')
        ->set('deposit_amount', '2500')
        ->call('deposit')
        ->assertRedirect('https://checkout.paystack.com/xyz');

    expect(Transaction::query()->sole()->status)->toBe(StatusTransaction::PROCESSING);
});

test('the add funds button is hidden when no vendor is configured', function () {
    config(['payments.vendors' => []]);

    Livewire::actingAs($this->member)
        ->test('pages::user.transactions')
        ->assertDontSee('Add funds');
});
