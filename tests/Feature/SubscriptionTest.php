<?php

use App\Enums\PaymentVendorEnum;
use App\Enums\StatusDefault;
use App\Enums\StatusSubscription;
use App\Enums\StatusTransaction;
use App\Enums\StatusYes;
use App\Enums\SubscriptionFeatureEnum;
use App\Enums\SubscriptionIntervalEnum;
use App\Enums\TransactionGroupEnum;
use App\Enums\TransactionTypeEnum;
use App\Enums\TransactionWalletEnum;
use App\Enums\UserTypeEnum;
use App\Events\PaymentNeedsReview;
use App\Events\SubscriptionEnded;
use App\Events\SubscriptionPaymentFailed;
use App\Events\SubscriptionRenewed;
use App\Events\SubscriptionStarted;
use App\Models\ActivityLog;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Services\Payment\PaymentService;
use App\Services\Payment\SubscriptionService;
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
        'payments.vendors.paypal.client_id' => 'client',
        'payments.vendors.paypal.secret' => 'secret',
        'payments.vendors.paypal.webhook_id' => 'WH-ID',
        'payments.vendors.korapay.secret' => 'sk_test_kora',
    ]);

    $this->member = userOfType(UserTypeEnum::USER, ['email_verified_at' => now(), 'email' => 'member@example.test']);
    $this->admin = userOfType(UserTypeEnum::ADMIN, ['email_verified_at' => now()]);

    $this->plan = SubscriptionPlan::query()->create([
        'name' => 'Pro',
        'slug' => 'pro',
        'status' => StatusDefault::ACTIVE,
    ]);

    $this->price = $this->plan->prices()->create([
        'billing_interval' => SubscriptionIntervalEnum::MONTHLY,
        'amount' => 5000,
        'currency' => 'NGN',
        'status' => StatusDefault::ACTIVE,
    ]);
});

/**
 * Subscribe through Paystack and confirm the first payment, with Paystack
 * faked throughout.
 */
function activePaystackSubscription($test): Subscription
{
    Http::fake([
        'api.paystack.co/plan' => Http::response(['status' => true, 'data' => ['plan_code' => 'PLN_pro']]),
        'api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/sub']]),
        'api.paystack.co/transaction/verify/TXN-*' => Http::response(['status' => true, 'data' => [
            'id' => 1001, 'status' => 'success', 'amount' => 500000, 'currency' => 'NGN',
            'channel' => 'card', 'customer' => ['id' => 555, 'email' => 'member@example.test'],
        ]]),
    ]);

    $transaction = app(SubscriptionService::class)->subscribe($test->member, $test->price, PaymentVendorEnum::PAYSTACK);
    app(PaymentService::class)->verify($transaction);

    return $transaction->transactionable->fresh();
}

function postPaystackWebhook($test, array $payload)
{
    $body = json_encode($payload);

    return $test->call('POST', route('payments.webhook', ['vendor' => 'paystack']), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, 'sk_test_paystack'),
    ], $body);
}

function postStripeWebhook($test, array $payload)
{
    $body = json_encode($payload);
    $time = now()->getTimestamp();

    return $test->call('POST', route('payments.webhook', ['vendor' => 'stripe']), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => "t={$time},v1=".hash_hmac('sha256', "{$time}.{$body}", 'whsec_test'),
    ], $body);
}

// ||||||||||||||||||||||||||||||||||||||||||||||||
// VENDORS AND PLANS

test('only vendors that bill subscriptions are offered for a plan', function () {
    expect(app(SubscriptionService::class)->vendorOptions())
        ->toContain(PaymentVendorEnum::PAYSTACK, PaymentVendorEnum::STRIPE, PaymentVendorEnum::PAYPAL)
        ->not->toContain(PaymentVendorEnum::KORAPAY);
});

test('an admin creates a plan with a price an interval and its features', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.subscriptions')
        ->call('create')
        ->set('name', 'Team')
        ->set('prices.monthly', '10000')
        ->set('prices.annually', '117600')
        ->set('trial_days', '14')
        ->set('is_recommended', true)
        ->set('includes_plan_id', (string) $this->plan->id)
        ->set('featureOn.api-access', true)
        ->set('featureOn.team-members', true)
        ->set('featureLimits.team-members', '5')
        ->call('save')
        ->assertHasNoErrors();

    $plan = SubscriptionPlan::query()->with(['prices', 'features'])->where('slug', 'team')->sole();

    expect($plan->activePrices()->map(fn ($price) => [$price->billing_interval, (float) $price->amount])->all())
        ->toBe([[SubscriptionIntervalEnum::MONTHLY, 10000.0], [SubscriptionIntervalEnum::ANNUALLY, 117600.0]])
        ->and($plan->trial_days)->toBe(14)
        ->and($plan->is_recommended)->toBe(StatusYes::YES)
        ->and($plan->includes_plan_id)->toBe($this->plan->id)
        ->and($plan->grants(SubscriptionFeatureEnum::API_ACCESS))->toBeTrue()
        ->and($plan->limitOf(SubscriptionFeatureEnum::TEAM_MEMBERS))->toBe(5)
        ->and($plan->priceFor(SubscriptionIntervalEnum::ANNUALLY)->savingsAgainst($plan->basePrice()))->toBe(2);
});

test('a plan needs a price unless it is free', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.subscriptions')
        ->call('create')
        ->set('name', 'Nothing')
        ->call('save')
        ->assertHasErrors();

    Livewire::actingAs($this->admin)
        ->test('pages::admin.subscriptions')
        ->call('create')
        ->set('name', 'Starter')
        ->set('is_free', true)
        ->set('trial_days', '7')
        ->call('save')
        ->assertHasNoErrors();

    expect(SubscriptionPlan::query()->where('slug', 'starter')->sole())
        ->is_free->toBe(StatusYes::YES)
        ->trial_days->toBe(0);
});

test('only one plan is free and only one is recommended', function () {
    $this->plan->update(['is_free' => StatusYes::YES, 'is_recommended' => StatusYes::YES]);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.subscriptions')
        ->call('create')
        ->set('name', 'Basic')
        ->set('is_free', true)
        ->set('is_recommended', true)
        ->call('save')
        ->assertHasNoErrors();

    expect($this->plan->fresh())
        ->is_free->toBe(StatusYes::NO)
        ->is_recommended->toBe(StatusYes::NO);
});

test('a changed amount forgets that price on the vendors but keeps the product, and an emptied interval is switched off', function () {
    $this->plan->vendorPlans()->create([
        'subscription_plan_price_id' => $this->price->id,
        'vendor' => PaymentVendorEnum::STRIPE,
        'vendor_plan_id' => 'price_old',
        'vendor_product_id' => 'prod_1',
    ]);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.subscriptions')
        ->call('edit', $this->plan->id)
        ->assertSet('prices.monthly', 5000.0)
        ->set('prices.monthly', '6000')
        ->call('save')
        ->assertHasNoErrors();

    expect($this->price->vendorPlans()->sole())
        ->vendor_plan_id->toBe('')
        ->vendor_product_id->toBe('prod_1');

    Livewire::actingAs($this->admin)
        ->test('pages::admin.subscriptions')
        ->call('edit', $this->plan->id)
        ->set('prices.monthly', null)
        ->set('prices.annually', '60000')
        ->call('save')
        ->assertHasNoErrors();

    expect($this->price->fresh()->status)->toBe(StatusDefault::INACTIVE)
        ->and($this->plan->prices()->count())->toBe(2);
});

test('a plan cannot include a plan that already includes it', function () {
    $team = SubscriptionPlan::query()->create(['name' => 'Team', 'slug' => 'team', 'includes_plan_id' => $this->plan->id]);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.subscriptions')
        ->call('edit', $this->plan->id)
        ->set('includes_plan_id', (string) $team->id)
        ->call('save')
        ->assertHasErrors('includes_plan_id');
});

test('a plan another plan includes cannot be deleted', function () {
    SubscriptionPlan::query()->create(['name' => 'Team', 'slug' => 'team', 'includes_plan_id' => $this->plan->id]);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.subscriptions')
        ->call('confirmDeletePlan', $this->plan->id)
        ->call('deletePlan')
        ->assertHasErrors();

    expect($this->plan->fresh())->not->toBeNull();
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// FEATURES

test('a plan grants what it includes, and the more generous limit wins', function () {
    $this->plan->features()->createMany([
        ['feature' => SubscriptionFeatureEnum::PRIORITY_SUPPORT],
        ['feature' => SubscriptionFeatureEnum::TEAM_MEMBERS, 'feature_limit' => 3],
    ]);

    $team = SubscriptionPlan::query()->create(['name' => 'Team', 'slug' => 'team', 'includes_plan_id' => $this->plan->id]);
    $team->features()->createMany([
        ['feature' => SubscriptionFeatureEnum::TEAM_MEMBERS, 'feature_limit' => null],
        ['feature' => SubscriptionFeatureEnum::API_ACCESS],
    ]);

    expect($team->grants(SubscriptionFeatureEnum::PRIORITY_SUPPORT))->toBeTrue()
        ->and($team->limitOf(SubscriptionFeatureEnum::TEAM_MEMBERS))->toBeNull()
        ->and($team->limitOf(SubscriptionFeatureEnum::PROJECTS))->toBe(0)
        ->and($team->featureLines())->toBe(['API access', 'Unlimited team members']);
});

test('feature checks fall back to the free plan when nothing is subscribed', function () {
    $service = app(SubscriptionService::class);

    expect($service->allows($this->member, SubscriptionFeatureEnum::API_ACCESS))->toBeFalse()
        ->and($service->limitFor($this->member, SubscriptionFeatureEnum::PROJECTS))->toBe(0);

    $free = SubscriptionPlan::query()->create(['name' => 'Free', 'slug' => 'free', 'is_free' => StatusYes::YES]);
    $free->features()->create(['feature' => SubscriptionFeatureEnum::PROJECTS, 'feature_limit' => 2]);

    expect($service->limitFor($this->member, SubscriptionFeatureEnum::PROJECTS))->toBe(2);

    $this->plan->features()->create(['feature' => SubscriptionFeatureEnum::API_ACCESS]);
    activePaystackSubscription($this);

    expect($service->allows($this->member, SubscriptionFeatureEnum::API_ACCESS))->toBeTrue()
        ->and($service->limitFor($this->member, SubscriptionFeatureEnum::PROJECTS))->toBe(0);
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// TRIALS

test('a member starts a card-less trial once, and it ends on its own', function () {
    Event::fake([SubscriptionEnded::class]);

    $this->plan->update(['trial_days' => 14]);
    $this->plan->features()->create(['feature' => SubscriptionFeatureEnum::API_ACCESS]);

    Livewire::actingAs($this->member)
        ->test('pages::user.subscriptions')
        ->assertSee('Try it free for 14 days')
        ->call('startTrial', $this->plan->id)
        ->assertHasNoErrors();

    $trial = $this->member->subscriptions()->sole();

    expect($trial->status)->toBe(StatusSubscription::TRIALING)
        ->and($trial->vendor)->toBeNull()
        ->and(app(SubscriptionService::class)->allows($this->member, SubscriptionFeatureEnum::API_ACCESS))->toBeTrue();

    $this->travelTo($trial->current_period_ends_at->addMinute());
    $this->artisan('payments:sync-subscriptions')->assertSuccessful();

    expect($trial->fresh()->status)->toBe(StatusSubscription::EXPIRED)
        ->and(app(SubscriptionService::class)->allows($this->member, SubscriptionFeatureEnum::API_ACCESS))->toBeFalse()
        ->and(app(SubscriptionService::class)->startTrial($this->member, $this->plan))->toBe('You have already had a free trial.');

    Event::assertDispatched(SubscriptionEnded::class);
});

test('paying during a trial ends the trial', function () {
    $this->plan->update(['trial_days' => 14]);
    $trial = app(SubscriptionService::class)->startTrial($this->member, $this->plan);

    $subscription = activePaystackSubscription($this);

    expect($subscription->status)->toBe(StatusSubscription::ACTIVE)
        ->and($trial->fresh()->status)->toBe(StatusSubscription::EXPIRED)
        ->and(app(SubscriptionService::class)->accessFor($this->member)->is($subscription))->toBeTrue();
});

test('the pricing cards show the saving on a longer interval and the recommended plan', function () {
    $this->plan->update(['is_recommended' => StatusYes::YES]);
    $this->plan->prices()->create([
        'billing_interval' => SubscriptionIntervalEnum::ANNUALLY,
        'amount' => 54000,
        'currency' => 'NGN',
        'status' => StatusDefault::ACTIVE,
    ]);

    Livewire::actingAs($this->member)
        ->test('pages::user.subscriptions')
        ->assertSet('interval', 'monthly')
        ->assertSee('Recommended')
        ->assertDontSee('Save 10%')
        ->set('interval', 'annually')
        ->assertSee('Save 10%');
});

test('a plan somebody subscribed to cannot be deleted', function () {
    activePaystackSubscription($this);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.subscriptions')
        ->call('confirmDeletePlan', $this->plan->id)
        ->call('deletePlan')
        ->assertHasErrors();

    expect($this->plan->fresh())->not->toBeNull();
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// PAYSTACK

test('subscribing syncs the plan, writes a pending subscription and opens the checkout', function () {
    Http::fake([
        'api.paystack.co/plan' => Http::response(['status' => true, 'data' => ['plan_code' => 'PLN_pro']]),
        'api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/sub']]),
    ]);

    $transaction = app(SubscriptionService::class)->subscribe($this->member, $this->price, PaymentVendorEnum::PAYSTACK);

    expect($transaction->gateway->gateway_url)->toBe('https://checkout.paystack.com/sub')
        ->and($transaction->transaction_group)->toBe(TransactionGroupEnum::SUBSCRIPTION)
        ->and($transaction->transaction_type)->toBe(TransactionTypeEnum::DIRECT)
        ->and($transaction->transaction_wallet)->toBe(TransactionWalletEnum::NONE)
        ->and($transaction->transactionable)->toBeInstanceOf(Subscription::class)
        ->and($transaction->transactionable->status)->toBe(StatusSubscription::PENDING)
        ->and($transaction->transactionable->vendor_plan_id)->toBe('PLN_pro')
        ->and($this->plan->vendorPlans()->sole()->vendor_plan_id)->toBe('PLN_pro');

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/plan')
        && $request['interval'] === 'monthly' && $request['amount'] === 500000);
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/transaction/initialize')
        && $request['plan'] === 'PLN_pro' && $request['reference'] === $transaction->reference);
});

test('a confirmed first payment starts the subscription', function () {
    Event::fake([SubscriptionStarted::class]);

    $subscription = activePaystackSubscription($this);

    expect($subscription->status)->toBe(StatusSubscription::ACTIVE)
        ->and($subscription->vendor_customer_id)->toBe('555')
        ->and($subscription->current_period_ends_at->isSameDay(now()->addMonthNoOverflow()))->toBeTrue()
        ->and($subscription->grantsAccess())->toBeTrue()
        ->and(app(SubscriptionService::class)->accessFor($this->member)?->is($subscription))->toBeTrue();

    Event::assertDispatched(SubscriptionStarted::class);
});

test('a checkout the vendor refuses ends the pending subscription', function () {
    Http::fake([
        'api.paystack.co/plan' => Http::response(['status' => true, 'data' => ['plan_code' => 'PLN_pro']]),
        'api.paystack.co/transaction/initialize' => Http::response(['status' => false, 'message' => 'Plan inactive'], 400),
    ]);

    expect(app(SubscriptionService::class)->subscribe($this->member, $this->price, PaymentVendorEnum::PAYSTACK))->toBe('Plan inactive')
        ->and(Subscription::query()->sole()->status)->toBe(StatusSubscription::EXPIRED);
});

test('an account with access cannot take out a second subscription', function () {
    activePaystackSubscription($this);

    expect(app(SubscriptionService::class)->subscribe($this->member, $this->price, PaymentVendorEnum::PAYSTACK))
        ->toContain('already have an active subscription');
});

test('Paystack\'s subscription.create links the vendor subscription and its token', function () {
    $subscription = activePaystackSubscription($this);

    Http::fake([
        'api.paystack.co/subscription/SUB_abc' => Http::response(['status' => true, 'data' => [
            'subscription_code' => 'SUB_abc', 'status' => 'active', 'email_token' => 'tok_1',
            'next_payment_date' => now()->addMonth()->toIso8601String(),
        ]]),
    ]);

    postPaystackWebhook($this, [
        'event' => 'subscription.create',
        'data' => [
            'subscription_code' => 'SUB_abc', 'email_token' => 'tok_1', 'status' => 'active',
            'plan' => ['plan_code' => 'PLN_pro'], 'customer' => ['email' => 'member@example.test'],
        ],
    ])->assertOk();

    expect($subscription->fresh())
        ->vendor_subscription_id->toBe('SUB_abc')
        ->vendor_token->toBe('tok_1')
        ->status->toBe(StatusSubscription::ACTIVE);
});

test('a Paystack renewal is checked with Paystack and written once', function () {
    Event::fake([SubscriptionRenewed::class]);

    $subscription = activePaystackSubscription($this);
    $subscription->update(['vendor_subscription_id' => 'SUB_abc', 'current_period_ends_at' => now()->addDay()]);

    Http::fake([
        'api.paystack.co/transaction/verify/psk_renew_1' => Http::response(['status' => true, 'data' => [
            'id' => 2002, 'status' => 'success', 'amount' => 500000, 'currency' => 'NGN', 'channel' => 'card',
        ]]),
    ]);

    $renewal = [
        'event' => 'charge.success',
        'data' => [
            'reference' => 'psk_renew_1', 'status' => 'success', 'amount' => 500000,
            'plan' => ['plan_code' => 'PLN_pro'], 'customer' => ['email' => 'member@example.test'],
        ],
    ];

    postPaystackWebhook($this, $renewal)->assertOk();

    // The same charge reported again under a different body.
    postPaystackWebhook($this, [...$renewal, 'resent' => true])->assertOk();

    $rows = $subscription->transactions()->orderBy('id')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows[1]->status)->toBe(StatusTransaction::CONFIRMED)
        ->and($rows[1]->gateway->gateway_request_id)->toBe('psk_renew_1')
        ->and($subscription->fresh()->current_period_ends_at->isAfter(now()->addWeeks(4)))->toBeTrue();

    Event::assertDispatchedTimes(SubscriptionRenewed::class, 1);
});

test('a short renewal is held for review', function () {
    Event::fake([PaymentNeedsReview::class, SubscriptionRenewed::class]);

    $subscription = activePaystackSubscription($this);

    Http::fake([
        'api.paystack.co/transaction/verify/psk_short' => Http::response(['status' => true, 'data' => [
            'status' => 'success', 'amount' => 100000, 'currency' => 'NGN',
        ]]),
    ]);

    postPaystackWebhook($this, ['event' => 'charge.success', 'data' => [
        'reference' => 'psk_short', 'plan' => ['plan_code' => 'PLN_pro'], 'customer' => ['email' => 'member@example.test'],
    ]])->assertOk();

    expect($subscription->transactions()->latest('id')->first())
        ->status->toBe(StatusTransaction::PROCESSING)
        ->gateway->failure_reason->toContain('against');

    Event::assertDispatched(PaymentNeedsReview::class);
    Event::assertNotDispatched(SubscriptionRenewed::class);
});

test('a failed renewal marks the subscription past due', function () {
    Event::fake([SubscriptionPaymentFailed::class]);

    $subscription = activePaystackSubscription($this);
    $subscription->update(['vendor_subscription_id' => 'SUB_abc']);

    postPaystackWebhook($this, ['event' => 'invoice.payment_failed', 'data' => [
        'subscription' => ['subscription_code' => 'SUB_abc'], 'customer' => ['email' => 'member@example.test'],
    ]])->assertOk();

    expect($subscription->fresh()->status)->toBe(StatusSubscription::PAST_DUE)
        ->and($subscription->fresh()->grantsAccess())->toBeTrue();

    Event::assertDispatched(SubscriptionPaymentFailed::class);
});

test('a member cancels, keeps access to the period end, and the sync ends it after', function () {
    Event::fake([SubscriptionEnded::class]);

    $subscription = activePaystackSubscription($this);
    $subscription->update(['vendor_subscription_id' => 'SUB_abc', 'vendor_token' => 'tok_1']);

    Http::fake(['api.paystack.co/subscription/disable' => Http::response(['status' => true])]);

    Livewire::actingAs($this->member)
        ->test('pages::user.subscriptions')
        ->assertSee('Pro')
        ->call('cancel')
        ->assertHasNoErrors();

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/subscription/disable')
        && $request['code'] === 'SUB_abc' && $request['token'] === 'tok_1');

    $subscription->refresh();

    expect($subscription->status)->toBe(StatusSubscription::CANCELLED)
        ->and($subscription->grantsAccess())->toBeTrue();

    $this->travelTo($subscription->current_period_ends_at->addDay());

    Http::fake(['api.paystack.co/subscription/SUB_abc' => Http::response(['status' => true, 'data' => [
        'subscription_code' => 'SUB_abc', 'status' => 'non-renewing',
    ]])]);

    $this->artisan('payments:sync-subscriptions')->assertSuccessful();

    expect($subscription->fresh()->status)->toBe(StatusSubscription::EXPIRED)
        ->and(app(SubscriptionService::class)->accessFor($this->member))->toBeNull();

    Event::assertDispatched(SubscriptionEnded::class);
});

test('a member chooses a plan and is sent to the checkout', function () {
    Http::fake([
        'api.paystack.co/plan' => Http::response(['status' => true, 'data' => ['plan_code' => 'PLN_pro']]),
        'api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/s1']]),
    ]);

    Livewire::actingAs($this->member)
        ->test('pages::user.subscriptions')
        ->call('choosePrice', $this->price->id)
        ->assertSet('vendor', 'paystack')
        ->call('subscribe')
        ->assertRedirect('https://checkout.paystack.com/s1');
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// STRIPE

test('Stripe subscriptions run through a subscription-mode session and invoice renewals', function () {
    Http::fake([
        'api.stripe.com/v1/products' => Http::response(['id' => 'prod_1']),
        'api.stripe.com/v1/prices' => Http::response(['id' => 'price_1']),
        'api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_sub', 'url' => 'https://checkout.stripe.com/c/cs_sub']),
        'api.stripe.com/v1/checkout/sessions/cs_sub' => Http::response([
            'id' => 'cs_sub', 'status' => 'complete', 'payment_status' => 'paid', 'amount_total' => 500000,
            'currency' => 'ngn', 'subscription' => 'sub_1', 'customer' => 'cus_1',
        ]),
        'api.stripe.com/v1/invoices/in_2' => Http::response(['id' => 'in_2', 'status' => 'paid', 'amount_paid' => 500000, 'currency' => 'ngn']),
    ]);

    $transaction = app(SubscriptionService::class)->subscribe($this->member, $this->price, PaymentVendorEnum::STRIPE);
    $subscription = $transaction->transactionable;

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/prices')
        && $request['recurring']['interval'] === 'month' && $request['unit_amount'] === 500000);
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/checkout/sessions')
        && $request['mode'] === 'subscription' && $request['line_items'][0]['price'] === 'price_1');

    app(PaymentService::class)->verify($transaction);

    expect($subscription->fresh())
        ->status->toBe(StatusSubscription::ACTIVE)
        ->vendor_subscription_id->toBe('sub_1')
        ->vendor_customer_id->toBe('cus_1');

    // The first invoice is the checkout itself and is not a renewal.
    postStripeWebhook($this, ['id' => 'evt_1', 'type' => 'invoice.paid', 'data' => ['object' => [
        'id' => 'in_1', 'billing_reason' => 'subscription_create', 'subscription' => 'sub_1',
    ]]])->assertOk();

    postStripeWebhook($this, ['id' => 'evt_2', 'type' => 'invoice.paid', 'data' => ['object' => [
        'id' => 'in_2', 'billing_reason' => 'subscription_cycle', 'subscription' => 'sub_1',
        'lines' => ['data' => [['period' => ['end' => now()->addMonths(2)->getTimestamp()]]]],
    ]]])->assertOk();

    expect($subscription->transactions()->count())->toBe(2)
        ->and($subscription->fresh()->current_period_ends_at->isSameDay(now()->addMonths(2)))->toBeTrue();
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// PAYPAL

test('the first PayPal sale is not counted again as a renewal', function () {
    Http::fake([
        'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'A21', 'expires_in' => 3600]),
        'api-m.sandbox.paypal.com/v1/catalogs/products' => Http::response(['id' => 'PROD-1']),
        'api-m.sandbox.paypal.com/v1/billing/plans' => Http::response(['id' => 'P-1']),
        'api-m.sandbox.paypal.com/v1/billing/subscriptions' => Http::response([
            'id' => 'I-SUB', 'links' => [['rel' => 'approve', 'href' => 'https://www.sandbox.paypal.com/webapps/billing/subscriptions?ba_token=x']],
        ]),
        'api-m.sandbox.paypal.com/v1/billing/subscriptions/I-SUB' => Http::response([
            'id' => 'I-SUB', 'status' => 'ACTIVE',
            'billing_info' => ['last_payment' => ['amount' => ['value' => '5000.00', 'currency_code' => 'NGN'], 'time' => now()->toIso8601String()]],
        ]),
        'api-m.sandbox.paypal.com/v1/billing/subscriptions/I-SUB/transactions*' => Http::response([
            'transactions' => [['id' => 'SALE-1', 'status' => 'COMPLETED']],
        ]),
        'api-m.sandbox.paypal.com/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'SUCCESS']),
    ]);

    $transaction = app(SubscriptionService::class)->subscribe($this->member, $this->price, PaymentVendorEnum::PAYPAL);
    $verified = app(PaymentService::class)->verify($transaction);

    expect($verified->status)->toBe(StatusTransaction::CONFIRMED)
        ->and($verified->gateway->gateway_request_id)->toBe('SALE-1');

    $body = json_encode(['id' => 'WH-SALE-1', 'event_type' => 'PAYMENT.SALE.COMPLETED', 'resource' => [
        'id' => 'SALE-1', 'billing_agreement_id' => 'I-SUB', 'amount' => ['total' => '5000.00', 'currency' => 'NGN'],
    ]]);

    $this->call('POST', route('payments.webhook', ['vendor' => 'paypal']), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_PAYPAL_AUTH_ALGO' => 'SHA256withRSA',
        'HTTP_PAYPAL_CERT_URL' => 'https://api.sandbox.paypal.com/cert',
        'HTTP_PAYPAL_TRANSMISSION_ID' => 't1',
        'HTTP_PAYPAL_TRANSMISSION_SIG' => 'sig',
        'HTTP_PAYPAL_TRANSMISSION_TIME' => now()->toIso8601String(),
    ], $body)->assertOk();

    expect($transaction->transactionable->transactions()->count())->toBe(1);
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// ADMIN

test('an admin cancels a subscriber and it is logged', function () {
    $subscription = activePaystackSubscription($this);
    $subscription->update(['vendor_subscription_id' => 'SUB_abc', 'vendor_token' => 'tok_1']);

    Http::fake(['api.paystack.co/subscription/disable' => Http::response(['status' => true])]);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.subscriptions', ['tab' => 'subscribers'])
        ->assertSee('member@example.test')
        ->call('confirmCancel', $subscription->id)
        ->call('cancelSubscription')
        ->assertHasNoErrors();

    expect($subscription->fresh()->status)->toBe(StatusSubscription::CANCELLED)
        ->and(ActivityLog::query()->where('description', 'like', '%cancelled%')->exists())->toBeTrue();
});

test('the subscriptions screens are behind their gate', function () {
    $clerk = adminWithRoles(roleWithGates('Clerk', ['transactions' => 'full']));

    $this->actingAs($clerk)->get(route('admin.subscriptions'))->assertNotFound();
    $this->actingAs($this->admin)->get(route('admin.subscriptions'))->assertOk();
    $this->actingAs($this->member)->get(route('user.subscriptions'))->assertOk();
});
