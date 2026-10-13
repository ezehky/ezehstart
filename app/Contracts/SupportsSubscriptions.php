<?php

namespace App\Contracts;

use App\Enums\StatusSubscription;
use App\Enums\StatusTransaction;
use App\Enums\SubscriptionEventEnum;
use App\Models\PaymentWebhook;
use App\Models\Subscription;
use App\Models\SubscriptionPlanPrice;
use App\Models\SubscriptionPlanVendor;
use App\Models\Transaction;
use App\Models\TransactionGateway;
use Carbon\CarbonInterface;

/**
 * A vendor that bills subscriptions itself — Paystack, Flutterwave, Stripe,
 * PayPal. A gateway service implements this beside PaymentGateway; one that
 * does not is simply never offered for a plan.
 *
 * The vendor owns the schedule and the retries. The kit keeps a Subscription
 * row in step with it and writes a ledger row for every payment.
 */
interface SupportsSubscriptions
{
    /**
     * Create one of a plan's prices on the vendor — a plan of its own on
     * Paystack and Flutterwave, a price under the plan's product on Stripe and
     * PayPal. $existing is a copy already there, whose product a new price can
     * hang off; the plan id is always new.
     *
     * @return string|array{vendor_plan_id: string, vendor_product_id: ?string}
     */
    public function syncPlan(SubscriptionPlanPrice $price, ?SubscriptionPlanVendor $existing = null): string|array;

    /**
     * Open the checkout for a subscription's first payment. $transaction is the
     * PROCESSING row for that payment; its reference goes to the vendor the same
     * way a one-off payment's does.
     *
     * @return string|array{url: string, gateway_id: ?string}
     */
    public function subscribe(Subscription $subscription, Transaction $transaction, string $vendorPlanId): string|array;

    /**
     * verify(), for a subscription's first payment, plus what the vendor knows
     * about the subscription it started.
     *
     * @return string|array{status: StatusTransaction, amount: ?float, currency: ?string, payment_id: ?string, channel: ?string, paid_at: ?CarbonInterface, message: ?string, raw: array<string, mixed>, subscription_id: ?string, customer_id: ?string}
     */
    public function verifySubscriptionCheckout(TransactionGateway $gateway): string|array;

    /**
     * Where the subscription stands on the vendor.
     *
     * @return string|array{status: StatusSubscription, vendor_subscription_id: ?string, period_ends_at: ?CarbonInterface, cancel_at_period_end: bool, token: ?string}
     */
    public function fetchSubscription(Subscription $subscription): string|array;

    /**
     * Stop the vendor billing it. Null when it worked, otherwise why not.
     */
    public function cancelSubscription(Subscription $subscription, bool $atPeriodEnd = true): ?string;

    /**
     * Read a stored webhook as a subscription event. Null when it is not one.
     *
     * @return ?array{event: SubscriptionEventEnum, vendor_subscription_id: ?string, customer_email: ?string, vendor_plan_id: ?string, payment_id: ?string, period_ends_at: ?CarbonInterface, token: ?string}
     */
    public function parseSubscriptionEvent(PaymentWebhook $webhook): ?array;

    /**
     * Ask the vendor about one renewal charge by its own id — the webhook said
     * it was paid; this is where that is checked.
     *
     * @return string|array{status: StatusTransaction, amount: ?float, currency: ?string, payment_id: ?string, channel: ?string, paid_at: ?CarbonInterface, message: ?string, raw: array<string, mixed>}
     */
    public function verifyRenewal(Subscription $subscription, string $paymentId): string|array;
}
