<?php

namespace App\Services\Payment\Gateways;

use App\Contracts\BasePaymentGateway;
use App\Contracts\SupportsSubscriptions;
use App\Enums\PaymentTraceStepEnum;
use App\Enums\PaymentVendorEnum;
use App\Enums\PaymentWebhookTopicEnum;
use App\Enums\StatusSubscription;
use App\Enums\StatusTransaction;
use App\Enums\SubscriptionEventEnum;
use App\Models\PaymentWebhook;
use App\Models\Subscription;
use App\Models\SubscriptionPlanPrice;
use App\Models\SubscriptionPlanVendor;
use App\Models\Transaction;
use App\Models\TransactionGateway;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;

/**
 * Stripe Checkout, over the REST API rather than stripe-php — form-encoded
 * requests, nothing the HTTP client cannot do on its own.
 *
 * A Checkout Session is opened per payment. Our reference rides along as the
 * session's client_reference_id; the session id is what verification asks
 * about. Webhooks are signed with the endpoint's whsec_ secret over
 * "{timestamp}.{body}", and anything older than five minutes is refused so a
 * captured delivery cannot be replayed later.
 *
 * Subscriptions: a plan is a Product with a recurring Price, and the first
 * payment is a Checkout Session in subscription mode. Renewals are invoice.paid
 * with a billing reason other than subscription_create; the invoice id is the
 * renewal's id, and it is fetched back from Stripe before anything is written.
 */
#[Singleton]
class StripeGatewayService extends BasePaymentGateway implements SupportsSubscriptions
{
    protected const BASE_URL = 'https://api.stripe.com/v1';

    /** Seconds a signed webhook stays acceptable. Stripe's own libraries use 300. */
    protected const SIGNATURE_TOLERANCE = 300;

    /**
     * Currencies Stripe counts in whole units. Sending a hundredfold amount in
     * one of these charges the customer a hundred times the price.
     */
    protected const ZERO_DECIMAL = [
        'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG',
        'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
    ];

    public function vendor(): PaymentVendorEnum
    {
        return PaymentVendorEnum::STRIPE;
    }

    protected function requiredKeys(): array
    {
        return ['secret', 'webhook_secret'];
    }

    protected function client(): PendingRequest
    {
        return $this->http()
            ->asForm()
            ->baseUrl(self::BASE_URL)
            ->withToken((string) $this->config('secret'));
    }

    // Actions

    public function initialize(Transaction $transaction): string|array
    {
        $currency = $this->currency();

        $response = $this->send(PaymentTraceStepEnum::INITIALIZE, 'POST', '/checkout/sessions', [
            'mode' => 'payment',
            'client_reference_id' => $transaction->reference,
            'customer_email' => $transaction->user->email,
            'success_url' => $this->callbackUrl($transaction),
            'cancel_url' => $this->callbackUrl($transaction, cancelled: true),
            // Stripe refuses an expiry under thirty minutes.
            'expires_at' => now()->addMinutes(max(30, min($this->expiresInMinutes(), 1440)))->getTimestamp(),
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => strtolower($currency),
                    'unit_amount' => $this->toStripeAmount((float) $transaction->amount, $currency),
                    'product_data' => ['name' => $transaction->description],
                ],
            ]],
            'metadata' => ['transaction_reference' => $transaction->reference],
            'payment_intent_data' => [
                'metadata' => ['transaction_reference' => $transaction->reference],
            ],
        ], $transaction);

        if (! $response?->successful()) {
            return $this->errorMessage($response);
        }

        return [
            'url' => (string) $response->json('url'),
            'gateway_id' => $response->json('id'),
        ];
    }

    public function verify(TransactionGateway $gateway): string|array
    {
        if (! $gateway->gateway_id) {
            return 'This checkout was never opened with Stripe.';
        }

        $response = $this->send(PaymentTraceStepEnum::VERIFY, 'GET', '/checkout/sessions/'.rawurlencode($gateway->gateway_id), transaction: $gateway->transaction);

        if (! $response?->successful()) {
            return $this->errorMessage($response);
        }

        $data = (array) $response->json();
        $currency = strtoupper((string) ($data['currency'] ?? ''));

        $status = match (true) {
            \in_array($data['payment_status'] ?? null, ['paid', 'no_payment_required'], true) => StatusTransaction::CONFIRMED,
            ($data['status'] ?? null) === 'expired' => StatusTransaction::CANCELLED,
            default => StatusTransaction::PROCESSING,
        };

        $intent = $data['payment_intent'] ?? null;

        return $this->verification(
            $status,
            isset($data['amount_total']) ? $this->fromStripeAmount((int) $data['amount_total'], $currency) : null,
            $currency ?: null,
            // The payment intent is what a refund is issued against, so it is the
            // id worth keeping once the session has done its job.
            \is_array($intent) ? ($intent['id'] ?? null) : $intent,
            $data['payment_method_types'][0] ?? null,
            $status->isConfirmed() ? now() : null,
            raw: $data,
        );
    }

    public function verifyWebhook(Request $request): bool
    {
        $header = (string) $request->header('stripe-signature');

        if ($header === '') {
            return false;
        }

        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            [$name, $value] = array_pad(explode('=', trim($part), 2), 2, '');

            if ($name === 't') {
                $timestamp = (int) $value;
            } elseif ($name === 'v1') {
                $signatures[] = $value;
            }
        }

        if (! $timestamp || ! $signatures || abs(now()->getTimestamp() - $timestamp) > self::SIGNATURE_TOLERANCE) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), (string) $this->config('webhook_secret'));

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    public function parseWebhook(Request $request): array
    {
        $type = (string) $request->input('type');

        return [
            'event_key' => (string) ($request->input('id') ?: $this->bodyKey($request)),
            'event_name' => $type ?: null,
            'topic' => match (true) {
                str_starts_with($type, 'checkout.session.'), str_starts_with($type, 'payment_intent.') => PaymentWebhookTopicEnum::CHARGE,
                $type === 'charge.refunded', str_starts_with($type, 'refund.'), str_starts_with($type, 'charge.refund.') => PaymentWebhookTopicEnum::REFUND,
                str_starts_with($type, 'customer.subscription.'), str_starts_with($type, 'invoice.') => PaymentWebhookTopicEnum::SUBSCRIPTION,
                default => PaymentWebhookTopicEnum::OTHER,
            },
            'reference' => $request->input('data.object.client_reference_id')
                ?? $request->input('data.object.metadata.transaction_reference')
                ?? $request->input('data.object.payment_intent'),
        ];
    }

    public function ping(): ?string
    {
        $response = $this->send(PaymentTraceStepEnum::HEALTH, 'GET', '/balance');

        return $response?->successful() ? null : $this->errorMessage($response);
    }

    public function refund(TransactionGateway $original, Transaction $refund, float $amount, ?string $reason = null): string|array
    {
        if (! $paymentId = $this->paymentId($original)) {
            return 'This payment was never verified with Stripe, so there is no payment intent to refund.';
        }

        $currency = $original->currency ?: $this->currency();

        // The refund reference as the idempotency key: a double-submitted refund
        // is the same request twice, and Stripe answers it once.
        $response = $this->send(PaymentTraceStepEnum::REFUND, 'POST', '/refunds', [
            'payment_intent' => $paymentId,
            'amount' => $this->toStripeAmount($amount, $currency),
            'reason' => 'requested_by_customer',
            'metadata' => [
                'transaction_reference' => $original->transaction->reference,
                'refund_reference' => $refund->reference,
                'note' => str()->limit((string) $reason, 450),
            ],
        ], $refund, $this->client()->withHeaders(['Idempotency-Key' => "refund-{$refund->reference}"]));

        if (! $response?->successful()) {
            return $this->errorMessage($response);
        }

        return $this->fromRefund((array) $response->json());
    }

    public function refundStatus(TransactionGateway $refund): string|array
    {
        if (! $refund->gateway_id) {
            return 'Stripe never acknowledged this refund.';
        }

        $response = $this->send(PaymentTraceStepEnum::REFUND, 'GET', '/refunds/'.rawurlencode($refund->gateway_id), transaction: $refund->transaction);

        if (! $response?->successful()) {
            return $this->errorMessage($response);
        }

        return $this->fromRefund((array) $response->json());
    }

    // Subscriptions

    public function syncPlan(SubscriptionPlanPrice $price, ?SubscriptionPlanVendor $existing = null): string|array
    {
        $productId = $existing?->vendor_product_id;

        if (! $productId) {
            $product = $this->send(PaymentTraceStepEnum::SUBSCRIPTION, 'POST', '/products', [
                'name' => $price->plan->name,
                'metadata' => ['subscription_plan_id' => $price->subscription_plan_id],
            ]);

            if (! $product?->successful()) {
                return $this->errorMessage($product);
            }

            $productId = (string) $product->json('id');
        }

        // Stripe prices cannot be edited, so a changed price is always a new one
        // on the same product. The monthly and the yearly are two prices on it.
        [$unit, $count] = $price->billing_interval->unitAndCount();

        $response = $this->send(PaymentTraceStepEnum::SUBSCRIPTION, 'POST', '/prices', [
            'product' => $productId,
            'currency' => strtolower($price->currency),
            'unit_amount' => $this->toStripeAmount((float) $price->amount, $price->currency),
            'recurring' => ['interval' => $unit, 'interval_count' => $count],
            'metadata' => ['subscription_plan_id' => $price->subscription_plan_id, 'subscription_plan_price_id' => $price->id],
        ]);

        if (! $response?->successful()) {
            return $this->errorMessage($response);
        }

        return ['vendor_plan_id' => (string) $response->json('id'), 'vendor_product_id' => $productId];
    }

    public function subscribe(Subscription $subscription, Transaction $transaction, string $vendorPlanId): string|array
    {
        $response = $this->send(PaymentTraceStepEnum::INITIALIZE, 'POST', '/checkout/sessions', [
            'mode' => 'subscription',
            'client_reference_id' => $transaction->reference,
            'customer_email' => $transaction->user->email,
            'success_url' => $this->callbackUrl($transaction),
            'cancel_url' => $this->callbackUrl($transaction, cancelled: true),
            'line_items' => [['price' => $vendorPlanId, 'quantity' => 1]],
            'metadata' => ['transaction_reference' => $transaction->reference],
            'subscription_data' => [
                'metadata' => [
                    'subscription_id' => $subscription->id,
                    'transaction_reference' => $transaction->reference,
                ],
            ],
        ], $transaction);

        if (! $response?->successful()) {
            return $this->errorMessage($response);
        }

        return ['url' => (string) $response->json('url'), 'gateway_id' => $response->json('id')];
    }

    public function verifySubscriptionCheckout(TransactionGateway $gateway): string|array
    {
        $result = $this->verify($gateway);

        if (\is_string($result)) {
            return $result;
        }

        $subscription = $result['raw']['subscription'] ?? null;
        $customer = $result['raw']['customer'] ?? null;

        return [
            ...$result,
            'subscription_id' => \is_array($subscription) ? ($subscription['id'] ?? null) : $subscription,
            'customer_id' => \is_array($customer) ? ($customer['id'] ?? null) : $customer,
        ];
    }

    public function fetchSubscription(Subscription $subscription): string|array
    {
        if (! $subscription->vendor_subscription_id) {
            return 'Stripe has not reported a subscription id for this yet.';
        }

        $response = $this->send(PaymentTraceStepEnum::SUBSCRIPTION, 'GET', '/subscriptions/'.rawurlencode($subscription->vendor_subscription_id));

        if (! $response?->successful()) {
            return $this->errorMessage($response);
        }

        $data = (array) $response->json();
        $cancelAtPeriodEnd = (bool) ($data['cancel_at_period_end'] ?? false);

        // Newer API versions moved the period onto the subscription item.
        $periodEnd = $data['current_period_end'] ?? data_get($data, 'items.data.0.current_period_end');

        return $this->subscriptionState(
            match ((string) ($data['status'] ?? '')) {
                'active', 'trialing' => $cancelAtPeriodEnd ? StatusSubscription::CANCELLED : StatusSubscription::ACTIVE,
                'past_due', 'unpaid' => StatusSubscription::PAST_DUE,
                'canceled', 'incomplete_expired' => StatusSubscription::EXPIRED,
                default => StatusSubscription::PENDING,
            },
            $data['id'] ?? null,
            $this->parseDate($periodEnd),
            $cancelAtPeriodEnd,
        );
    }

    public function cancelSubscription(Subscription $subscription, bool $atPeriodEnd = true): ?string
    {
        if (! $subscription->vendor_subscription_id) {
            return 'Stripe has not reported a subscription id for this yet.';
        }

        $path = '/subscriptions/'.rawurlencode($subscription->vendor_subscription_id);

        $response = $atPeriodEnd
            ? $this->send(PaymentTraceStepEnum::SUBSCRIPTION, 'POST', $path, ['cancel_at_period_end' => 'true'])
            : $this->send(PaymentTraceStepEnum::SUBSCRIPTION, 'DELETE', $path);

        return $response?->successful() ? null : $this->errorMessage($response);
    }

    public function parseSubscriptionEvent(PaymentWebhook $webhook): ?array
    {
        $object = (array) data_get($webhook->payload, 'data.object', []);
        $subscriptionId = $object['subscription']
            ?? data_get($object, 'parent.subscription_details.subscription')
            ?? (str_starts_with((string) $webhook->event_name, 'customer.subscription.') ? ($object['id'] ?? null) : null);

        return match ((string) $webhook->event_name) {
            // The first invoice is the checkout itself, already settled through
            // the session; only the ones after it are renewals.
            'invoice.paid' => ($object['billing_reason'] ?? null) === 'subscription_create'
                ? null
                : $this->subscriptionEvent(
                    SubscriptionEventEnum::RENEWED,
                    $subscriptionId,
                    $object['customer_email'] ?? null,
                    paymentId: $object['id'] ?? null,
                    periodEndsAt: $this->parseDate(data_get($object, 'lines.data.0.period.end')),
                ),
            'invoice.payment_failed' => $this->subscriptionEvent(SubscriptionEventEnum::PAYMENT_FAILED, $subscriptionId, $object['customer_email'] ?? null),
            'customer.subscription.created', 'customer.subscription.updated', 'customer.subscription.deleted',
            'customer.subscription.paused', 'customer.subscription.resumed' => $this->subscriptionEvent(SubscriptionEventEnum::CHANGED, $subscriptionId),
            default => null,
        };
    }

    public function verifyRenewal(Subscription $subscription, string $paymentId): string|array
    {
        $response = $this->send(PaymentTraceStepEnum::VERIFY, 'GET', '/invoices/'.rawurlencode($paymentId));

        if (! $response?->successful()) {
            return $this->errorMessage($response);
        }

        $data = (array) $response->json();
        $currency = strtoupper((string) ($data['currency'] ?? ''));

        return $this->verification(
            match ((string) ($data['status'] ?? '')) {
                'paid' => StatusTransaction::CONFIRMED,
                'void', 'uncollectible' => StatusTransaction::FAILED,
                default => StatusTransaction::PROCESSING,
            },
            isset($data['amount_paid']) ? $this->fromStripeAmount((int) $data['amount_paid'], $currency) : null,
            $currency ?: null,
            $data['id'] ?? null,
            'card',
            $this->parseDate(data_get($data, 'status_transitions.paid_at')),
            raw: $data,
        );
    }

    // Tools

    /**
     * @param  array<string, mixed>  $data
     * @return array{status: StatusTransaction, refund_id: ?string, message: ?string, raw: array<string, mixed>}
     */
    protected function fromRefund(array $data): array
    {
        return $this->refundResult(
            match ((string) ($data['status'] ?? '')) {
                'succeeded' => StatusTransaction::CONFIRMED,
                'failed', 'canceled' => StatusTransaction::FAILED,
                default => StatusTransaction::PROCESSING,
            },
            $data['id'] ?? null,
            $data['failure_reason'] ?? $data['status'] ?? null,
            $data,
        );
    }

    protected function toStripeAmount(float $amount, string $currency): int
    {
        return \in_array(strtoupper($currency), self::ZERO_DECIMAL, true)
            ? (int) round($amount)
            : $this->toMinor($amount);
    }

    protected function fromStripeAmount(int $amount, string $currency): float
    {
        return \in_array(strtoupper($currency), self::ZERO_DECIMAL, true)
            ? (float) $amount
            : (float) $this->fromMinor($amount);
    }
}
