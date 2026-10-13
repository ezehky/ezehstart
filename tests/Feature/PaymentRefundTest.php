<?php

use App\Enums\GateAccessEnum;
use App\Enums\PaymentVendorEnum;
use App\Enums\PaymentWebhookTopicEnum;
use App\Enums\StatusPaymentWebhook;
use App\Enums\StatusTransaction;
use App\Enums\TransactionGroupEnum;
use App\Enums\TransactionTypeEnum;
use App\Enums\TransactionViaEnum;
use App\Enums\TransactionWalletEnum;
use App\Enums\UserTypeEnum;
use App\Events\PaymentNeedsReview;
use App\Events\RefundFailed;
use App\Events\RefundInitiated;
use App\Events\RefundProcessed;
use App\Models\PaymentWebhook;
use App\Models\Transaction;
use App\Services\Payment\PaymentService;
use App\Services\Payment\RefundService;
use App\Services\TransactionService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    // A fake that misses must fail the test, never reach a real provider.
    Http::preventStrayRequests();

    config([
        'payments.vendors.paystack.secret' => 'sk_test_paystack',
        'payments.vendors.stripe.secret' => 'sk_test_stripe',
        'payments.vendors.stripe.webhook_secret' => 'whsec_test',
    ]);

    $this->member = userOfType(UserTypeEnum::USER, ['email_verified_at' => now()]);
    $this->admin = userOfType(UserTypeEnum::ADMIN, ['email_verified_at' => now()]);
});

/**
 * A Paystack deposit taken all the way to CONFIRMED, with Paystack faked.
 */
function confirmedPaystackDeposit($user, float $amount = 5000): Transaction
{
    Http::fake([
        'api.paystack.co/transaction/initialize' => Http::response([
            'status' => true,
            'data' => ['authorization_url' => 'https://checkout.paystack.com/abc', 'reference' => 'x'],
        ]),
        'api.paystack.co/transaction/verify/*' => Http::response([
            'status' => true,
            'data' => ['id' => 4242, 'status' => 'success', 'amount' => (int) round($amount * 100), 'currency' => 'NGN', 'channel' => 'card'],
        ]),
    ]);

    $service = app(PaymentService::class);

    return $service->verify($service->checkout($user, PaymentVendorEnum::PAYSTACK, $amount, 'Wallet top-up'));
}

/**
 * Point Paystack's refund endpoints at fixed answers. Later fakes are checked
 * first, so these win over the ones confirmedPaystackDeposit() registered.
 */
function fakePaystackRefund(string $createStatus = 'pending', string $fetchStatus = 'processed'): void
{
    Http::fake([
        'api.paystack.co/refund' => Http::response(['status' => true, 'data' => ['id' => 777, 'status' => $createStatus]]),
        'api.paystack.co/refund/*' => Http::response(['status' => true, 'data' => ['id' => 777, 'status' => $fetchStatus]]),
    ]);
}

function signedPaystackWebhook($test, array $payload)
{
    $body = json_encode($payload);

    return $test->call('POST', route('payments.webhook', ['vendor' => 'paystack']), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, 'sk_test_paystack'),
    ], $body);
}

// ||||||||||||||||||||||||||||||||||||||||||||||||
// GATEWAY REFUNDS

test('a refund is its own row against the payment and debits only once confirmed', function () {
    Event::fake([RefundInitiated::class, RefundProcessed::class]);

    $deposit = confirmedPaystackDeposit($this->member, 5000);
    fakePaystackRefund('pending');

    $this->actingAs($this->admin);
    $refund = app(RefundService::class)->refund($deposit, 2000, 'Customer asked');

    expect($refund)->toBeInstanceOf(Transaction::class)
        ->and($refund->transaction_group)->toBe(TransactionGroupEnum::REFUND)
        ->and($refund->transaction_type)->toBe(TransactionTypeEnum::DEBIT)
        ->and($refund->status)->toBe(StatusTransaction::PROCESSING)
        ->and($refund->transactionable->is($deposit))->toBeTrue()
        ->and($refund->gateway->gateway_id)->toBe('777')
        ->and($deposit->fresh()->status)->toBe(StatusTransaction::CONFIRMED)
        ->and($deposit->fresh()->refundableAmount())->toBe(3000.0)
        ->and(app(TransactionService::class)->balanceFor($this->member))->toBe(5000.0);

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/refund')
        && $request['transaction'] === $deposit->reference
        && $request['amount'] === 200000);

    Event::assertDispatched(RefundInitiated::class);

    // Paystack finishes it; the webhook names the payment, and the pending
    // refund against it is asked about.
    signedPaystackWebhook($this, [
        'event' => 'refund.processed',
        'data' => ['transaction_reference' => $deposit->reference, 'status' => 'processed'],
    ])->assertOk();

    expect($refund->fresh()->status)->toBe(StatusTransaction::CONFIRMED)
        ->and(app(TransactionService::class)->balanceFor($this->member))->toBe(3000.0)
        ->and(PaymentWebhook::query()->sole()->status)->toBe(StatusPaymentWebhook::PROCESSED);

    Event::assertDispatched(RefundProcessed::class);
});

test('a vendor that refunds at once settles the refund straight away', function () {
    $deposit = confirmedPaystackDeposit($this->member, 100);
    fakePaystackRefund('processed');

    $refund = app(RefundService::class)->refund($deposit, 100, 'Duplicate charge');

    expect($refund->status)->toBe(StatusTransaction::CONFIRMED)
        ->and($deposit->fresh()->isRefundable())->toBeFalse()
        ->and(app(TransactionService::class)->balanceFor($this->member))->toBe(0.0);
});

test('nothing more than is left can be refunded, across several refunds', function () {
    $deposit = confirmedPaystackDeposit($this->member, 1000);
    fakePaystackRefund('pending');

    $service = app(RefundService::class);

    expect($service->refund($deposit, 600, 'First'))->toBeInstanceOf(Transaction::class)
        ->and($service->refund($deposit->fresh(), 600, 'Second'))->toContain('more than is left')
        ->and($service->refund($deposit->fresh(), 400, 'Third'))->toBeInstanceOf(Transaction::class)
        ->and($service->refund($deposit->fresh(), 1, 'Fourth'))->toContain('refunded in full');
});

test('a deposit the member has already spent cannot be refunded', function () {
    $deposit = confirmedPaystackDeposit($this->member, 1000);

    app(TransactionService::class)->record(
        $this->member,
        TransactionTypeEnum::DEBIT,
        TransactionGroupEnum::PURCHASE,
        800,
        'Spent it',
    );

    expect(app(RefundService::class)->refund($deposit, 500, 'Too late'))->toContain('balance is lower');
    Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/refund'));
});

test('a refund the vendor refuses is closed and frees the amount again', function () {
    Event::fake([RefundFailed::class]);

    $deposit = confirmedPaystackDeposit($this->member, 1000);

    Http::fake([
        'api.paystack.co/refund' => Http::response(['status' => false, 'message' => 'Transaction has been fully reversed'], 400),
    ]);

    $result = app(RefundService::class)->refund($deposit, 1000, 'Try');

    expect($result)->toBe('Transaction has been fully reversed')
        ->and($deposit->refunds()->sole()->status)->toBe(StatusTransaction::FAILED)
        ->and($deposit->fresh()->refundableAmount())->toBe(1000.0);

    Event::assertDispatched(RefundFailed::class);
});

test('Stripe refunds the payment intent with an idempotency key', function () {
    Http::fake([
        'api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_1', 'url' => 'https://checkout.stripe.com/c/cs_1']),
        'api.stripe.com/v1/checkout/sessions/cs_1' => Http::response([
            'id' => 'cs_1', 'status' => 'complete', 'payment_status' => 'paid', 'amount_total' => 5000, 'currency' => 'ngn', 'payment_intent' => 'pi_9',
        ]),
        'api.stripe.com/v1/refunds' => Http::response(['id' => 're_1', 'status' => 'succeeded']),
    ]);

    $service = app(PaymentService::class);
    $payment = $service->verify($service->checkout($this->member, PaymentVendorEnum::STRIPE, 50, 'Top-up'));

    $refund = app(RefundService::class)->refund($payment, 20, 'Partial');

    expect($refund->status)->toBe(StatusTransaction::CONFIRMED)
        ->and($refund->gateway->gateway_id)->toBe('re_1');

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v1/refunds')
        && $request['payment_intent'] === 'pi_9'
        && $request['amount'] === 2000
        && $request->header('Idempotency-Key')[0] === "refund-{$refund->reference}");
});

test('the reconcile sweep finishes a refund the webhook never reported', function () {
    $deposit = confirmedPaystackDeposit($this->member, 1000);
    fakePaystackRefund('pending', 'processed');

    $refund = app(RefundService::class)->refund($deposit, 1000, 'All of it');
    $refund->forceFill(['created_at' => now()->subHour()])->save();

    $this->artisan('payments:reconcile')->assertSuccessful();

    expect($refund->fresh()->status)->toBe(StatusTransaction::CONFIRMED);
});

test('a refund made outside the site is flagged for review', function () {
    Event::fake([PaymentNeedsReview::class]);

    $deposit = confirmedPaystackDeposit($this->member, 1000);

    signedPaystackWebhook($this, [
        'event' => 'refund.processed',
        'data' => ['transaction_reference' => $deposit->reference, 'status' => 'processed'],
    ])->assertOk();

    expect(PaymentWebhook::query()->sole())
        ->topic->toBe(PaymentWebhookTopicEnum::REFUND)
        ->status->toBe(StatusPaymentWebhook::IGNORED);

    Event::assertDispatched(PaymentNeedsReview::class, fn ($event) => $event->transaction->is($deposit));
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// BANK TRANSFERS AND DIRECT PAYMENTS

test('an approved bank transfer is refunded by hand and recorded at once', function () {
    Event::fake([RefundProcessed::class]);

    $transfer = app(TransactionService::class)->record(
        $this->member,
        TransactionTypeEnum::CREDIT,
        TransactionGroupEnum::DEPOSIT,
        2500,
        'Bank transfer',
        TransactionViaEnum::BANK_TRANSFER,
        vendor: PaymentVendorEnum::MANUAL,
    );

    $refund = app(RefundService::class)->refund($transfer, 2500, 'Sent back to their account');

    expect($refund->status)->toBe(StatusTransaction::CONFIRMED)
        ->and($refund->via)->toBe(TransactionViaEnum::BANK_TRANSFER)
        ->and($refund->gateway->vendor)->toBe(PaymentVendorEnum::MANUAL)
        ->and(app(TransactionService::class)->balanceFor($this->member))->toBe(0.0);

    Http::assertNothingSent();
    Event::assertDispatched(RefundProcessed::class);
});

test('a direct payment is refunded without touching the balance', function () {
    $purchase = app(TransactionService::class)->record(
        $this->member,
        TransactionTypeEnum::DIRECT,
        TransactionGroupEnum::PURCHASE,
        300,
        'Bank transfer for an order',
        TransactionViaEnum::BANK_TRANSFER,
        TransactionWalletEnum::NONE,
        vendor: PaymentVendorEnum::MANUAL,
    );

    $refund = app(RefundService::class)->refund($purchase, 300, 'Order cancelled');

    expect($refund->transaction_type)->toBe(TransactionTypeEnum::DIRECT)
        ->and($refund->transaction_wallet)->toBe(TransactionWalletEnum::NONE)
        ->and(app(TransactionService::class)->balanceFor($this->member))->toBe(0.0);
});

test('money that never came from outside cannot be refunded', function () {
    $adjustment = app(TransactionService::class)->adjust($this->member, 100, 'Goodwill');

    expect($adjustment->isRefundable())->toBeFalse()
        ->and(app(RefundService::class)->refund($adjustment, 100, 'No'))->toContain('adjustment');
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// ADMIN SCREENS

test('an admin refunds from the transactions screen', function () {
    $deposit = confirmedPaystackDeposit($this->member, 1000);
    fakePaystackRefund('processed');

    Livewire::actingAs($this->admin)
        ->test('pages::admin.transactions')
        ->call('confirmRefund', $deposit->id)
        ->assertSet('refund_amount', '1000')
        ->set('refund_reason', 'Customer asked')
        ->call('refund')
        ->assertHasNoErrors();

    expect($deposit->refunds()->sole()->status)->toBe(StatusTransaction::CONFIRMED);
});

test('refunding needs create access on transactions', function () {
    $deposit = confirmedPaystackDeposit($this->member, 1000);

    $viewer = adminWithRoles(roleWithGates('Viewer', ['transactions' => GateAccessEnum::VIEW->value]));

    Livewire::actingAs($viewer)
        ->test('pages::admin.transactions')
        ->call('confirmRefund', $deposit->id)
        ->assertHasErrors();

    expect($deposit->refunds()->count())->toBe(0);
});

test('an admin can ask the provider about a processing payment', function () {
    Http::fake([
        'api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://x.test']]),
        'api.paystack.co/transaction/verify/*' => Http::response(['status' => true, 'data' => ['status' => 'success', 'amount' => 10000, 'currency' => 'NGN']]),
    ]);

    $payment = app(PaymentService::class)->checkout($this->member, PaymentVendorEnum::PAYSTACK, 100, 'Top-up');

    Livewire::actingAs($this->admin)
        ->test('pages::admin.transactions')
        ->call('checkWithProvider', $payment->id)
        ->assertHasNoErrors();

    expect($payment->fresh()->status)->toBe(StatusTransaction::CONFIRMED);
});

test('the payments screen lists webhooks and retries a failed one', function () {
    $webhook = PaymentWebhook::query()->create([
        'vendor' => PaymentVendorEnum::PAYSTACK,
        'event_key' => 'k1',
        'event_name' => 'charge.success',
        'topic' => PaymentWebhookTopicEnum::CHARGE,
        'reference' => 'TXN-MISSING',
        'payload' => ['event' => 'charge.success'],
        'status' => StatusPaymentWebhook::FAILED,
    ]);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.payments')
        ->assertSee('charge.success')
        ->call('inspectWebhook', $webhook->id)
        ->assertSet('inspectTitle', $webhook->label())
        ->call('retryWebhook', $webhook->id)
        ->assertHasNoErrors();

    expect($webhook->fresh()->status)->toBe(StatusPaymentWebhook::IGNORED);
});

test('the payments screen checks every configured provider on request', function () {
    Http::fake([
        'api.paystack.co/balance' => Http::response(['status' => true]),
        'api.stripe.com/v1/balance' => Http::response(['error' => ['message' => 'Invalid API Key provided']], 401),
    ]);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.payments', ['tab' => 'gateways'])
        ->set('tab', 'gateways')
        ->call('checkHealth')
        ->assertSet('health', ['paystack' => null, 'stripe' => 'Invalid API Key provided'])
        ->assertSee('Connected')
        ->assertSee('Invalid API Key provided');
});

test('the payments screen is closed to a role without its gate', function () {
    $clerk = adminWithRoles(roleWithGates('Clerk', ['transactions' => GateAccessEnum::FULL->value]));

    $this->actingAs($clerk)->get(route('admin.payments'))->assertNotFound();
    $this->actingAs($this->admin)->get(route('admin.payments'))->assertOk();
});
