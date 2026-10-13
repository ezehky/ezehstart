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
use Carbon\CarbonInterface;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * PayPal Orders v2.
 *
 * PayPal is the one vendor where coming back from the checkout is not the end:
 * an APPROVED order has to be captured before any money moves. verify() does
 * that capture when it finds one approved, with our reference as the request
 * id, so a callback and a webhook racing each other capture once.
 *
 * Calls authenticate with a short-lived OAuth token, cached until a minute
 * before it expires. Webhooks are verified by asking PayPal, which needs the
 * webhook id the endpoint was registered under.
 *
 * Subscriptions: a plan is a catalog Product with a billing Plan, and the
 * customer approves a Subscription rather than an Order. There is no capture;
 * each payment, the first included, is a sale, reported as
 * PAYMENT.SALE.COMPLETED and checked by fetching the sale back.
 */
#[Singleton]
class PaypalGatewayService extends BasePaymentGateway implements SupportsSubscriptions
{
    /** Currencies PayPal will not take decimals for. */
    protected const ZERO_DECIMAL = ['HUF', 'JPY', 'TWD'];

    public function vendor(): PaymentVendorEnum
    {
        return PaymentVendorEnum::PAYPAL;
    }

    protected function requiredKeys(): array
    {
        return ['client_id', 'secret', 'webhook_id'];
    }

    protected function baseUrl(): string
    {
        return $this->isSandbox() ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';
    }

    protected function client(): PendingRequest
    {
        return $this->http()
            ->baseUrl($this->baseUrl())
            ->withToken((string) $this->accessToken());
    }

    // Actions

    public function initialize(Transaction $transaction): string|array
    {
        if (! $this->accessToken()) {
            return 'PayPal could not be reached. Please try again.';
        }

        $currency = $this->currency();

        $response = $this->send(PaymentTraceStepEnum::INITIALIZE, 'POST', '/v2/checkout/orders', [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => $transaction->reference,
                'custom_id' => $transaction->reference,
                'description' => str()->limit($transaction->description, 120),
                'amount' => [
                    'currency_code' => $currency,
                    'value' => $this->toPaypalAmount((float) $transaction->amount, $currency),
                ],
            ]],
            'payment_source' => [
                'paypal' => [
                    'experience_context' => [
                        'brand_name' => str()->limit((string) config('app.name'), 120),
                        'user_action' => 'PAY_NOW',
                        'shipping_preference' => 'NO_SHIPPING',
                        'return_url' => $this->callbackUrl($transaction),
                        'cancel_url' => $this->callbackUrl($transaction, cancelled: true),
                    ],
                ],
            ],
        ], $transaction, $this->client()->withHeaders(['PayPal-Request-Id' => "init-{$transaction->reference}"]));

        if (! $response?->successful()) {
            return $this->errorMessage($response);
        }

        $url = collect((array) $response->json('links'))
            ->first(fn ($link) => \in_array($link['rel'] ?? null, ['payer-action', 'approve'], true))['href'] ?? null;

        if (! $url) {
            return 'PayPal did not return a checkout link.';
        }

        return [
            'url' => (string) $url,
            'gateway_id' => $response->json('id'),
        ];
    }

    public function verify(TransactionGateway $gateway): string|array
    {
        if (! $gateway->gateway_id || ! $this->accessToken()) {
            return 'PayPal could not be asked about this payment.';
        }

        $orderId = rawurlencode($gateway->gateway_id);

        $response = $this->send(PaymentTraceStepEnum::VERIFY, 'GET', "/v2/checkout/orders/{$orderId}", transaction: $gateway->transaction);

        if (! $response?->successful()) {
            return $this->errorMessage($response);
        }

        $order = (array) $response->json();

        // Approved is the customer saying yes; capture is the money moving. The
        // request id makes a second capture of the same order a replay of the
        // first rather than a new charge.
        if (($order['status'] ?? null) === 'APPROVED') {
            $capture = $this->send(
                PaymentTraceStepEnum::VERIFY,
                'POST',
                "/v2/checkout/orders/{$orderId}/capture",
                transaction: $gateway->transaction,
                request: $this->client()->withHeaders(['PayPal-Request-Id' => "capture-{$gateway->transaction->reference}"])->withBody('{}', 'application/json'),
            );

            if (! $capture?->successful()) {
                return $this->errorMessage($capture);
            }

            $order = (array) $capture->json();
        }

        return $this->fromOrder($order);
    }

    public function verifyWebhook(Request $request): bool
    {
        $headers = [
            'auth_algo' => $request->header('paypal-auth-algo'),
            'cert_url' => $request->header('paypal-cert-url'),
            'transmission_id' => $request->header('paypal-transmission-id'),
            'transmission_sig' => $request->header('paypal-transmission-sig'),
            'transmission_time' => $request->header('paypal-transmission-time'),
        ];

        if (\in_array(null, $headers, true) || ! $this->accessToken()) {
            return false;
        }

        $response = $this->send(PaymentTraceStepEnum::WEBHOOK, 'POST', '/v1/notifications/verify-webhook-signature', [
            ...$headers,
            'webhook_id' => (string) $this->config('webhook_id'),
            'webhook_event' => json_decode($request->getContent(), true),
        ]);

        return $response?->successful() && $response->json('verification_status') === 'SUCCESS';
    }

    public function parseWebhook(Request $request): array
    {
        $type = (string) $request->input('event_type');

        return [
            'event_key' => (string) ($request->input('id') ?: $this->bodyKey($request)),
            'event_name' => $type ?: null,
            'topic' => match (true) {
                \in_array($type, ['PAYMENT.CAPTURE.REFUNDED', 'PAYMENT.CAPTURE.REVERSED'], true) => PaymentWebhookTopicEnum::REFUND,
                str_starts_with($type, 'CHECKOUT.ORDER.'), str_starts_with($type, 'PAYMENT.CAPTURE.') => PaymentWebhookTopicEnum::CHARGE,
                str_starts_with($type, 'BILLING.SUBSCRIPTION.'), str_starts_with($type, 'PAYMENT.SALE.') => PaymentWebhookTopicEnum::SUBSCRIPTION,
                default => PaymentWebhookTopicEnum::OTHER,
            },
            'reference' => $request->input('resource.custom_id')
                ?? $request->input('resource.purchase_units.0.custom_id')
                ?? $request->input('resource.purchase_units.0.reference_id')
                ?? $this->captureFromLinks((array) $request->input('resource.links', [])),
        ];
    }

    public function ping(): ?string
    {
        Cache::forget($this->tokenCacheKey());

        return $this->accessToken() ? null : 'PayPal refused the client id and secret.';
    }

    public function refund(TransactionGateway $original, Transaction $refund, float $amount, ?string $reason = null): string|array
    {
        if (! $captureId = $this->paymentId($original)) {
            return 'This payment was never captured through the kit, so there is no capture to refund.';
        }

        if (! $this->accessToken()) {
            return 'PayPal could not be reached. Please try again.';
        }

        $currency = $original->currency ?: $this->currency();

        // A subscription payment is a sale, not a capture, and is refunded
        // through the older sales API.
        if ($original->transaction->transactionable instanceof Subscription) {
            $response = $this->send(PaymentTraceStepEnum::REFUND, 'POST', '/v1/payments/sale/'.rawurlencode($captureId).'/refund', [
                'amount' => ['total' => $this->toPaypalAmount($amount, $currency), 'currency' => $currency],
                'invoice_number' => $refund->reference,
            ], $refund, $this->client()->withHeaders(['PayPal-Request-Id' => "refund-{$refund->reference}"]));

            if (! $response?->successful()) {
                return $this->errorMessage($response);
            }

            return $this->refundResult(
                match ((string) $response->json('state')) {
                    'completed' => StatusTransaction::CONFIRMED,
                    'failed', 'cancelled' => StatusTransaction::FAILED,
                    default => StatusTransaction::PROCESSING,
                },
                $response->json('id'),
                $response->json('state'),
                (array) $response->json(),
            );
        }

        $response = $this->send(PaymentTraceStepEnum::REFUND, 'POST', '/v2/payments/captures/'.rawurlencode($captureId).'/refund', array_filter([
            'amount' => [
                'value' => $this->toPaypalAmount($amount, $currency),
                'currency_code' => $currency,
            ],
            'invoice_id' => $refund->reference,
            'note_to_payer' => $reason ? str()->limit($reason, 250) : null,
        ]), $refund, $this->client()->withHeaders(['PayPal-Request-Id' => "refund-{$refund->reference}"]));

        if (! $response?->successful()) {
            return $this->errorMessage($response);
        }

        return $this->fromRefund((array) $response->json());
    }

    public function refundStatus(TransactionGateway $refund): string|array
    {
        if (! $refund->gateway_id || ! $this->accessToken()) {
            return 'PayPal could not be asked about this refund.';
        }

        $response = $this->send(PaymentTraceStepEnum::REFUND, 'GET', '/v2/payments/refunds/'.rawurlencode($refund->gateway_id), transaction: $refund->transaction);

        if (! $response?->successful()) {
            return $this->errorMessage($response);
        }

        return $this->fromRefund((array) $response->json());
    }

    // Subscriptions

    public function syncPlan(SubscriptionPlanPrice $price, ?SubscriptionPlanVendor $existing = null): string|array
    {
        if (! $this->accessToken()) {
            return 'PayPal could not be reached. Please try again.';
        }

        $productId = $existing?->vendor_product_id;

        if (! $productId) {
            $product = $this->send(PaymentTraceStepEnum::SUBSCRIPTION, 'POST', '/v1/catalogs/products', [
                'name' => str()->limit($price->plan->name, 120),
                'type' => 'SERVICE',
            ]);

            if (! $product?->successful()) {
                return $this->errorMessage($product);
            }

            $productId = (string) $product->json('id');
        }

        [$unit, $count] = $price->billing_interval->unitAndCount();

        $response = $this->send(PaymentTraceStepEnum::SUBSCRIPTION, 'POST', '/v1/billing/plans', [
            'product_id' => $productId,
            'name' => str()->limit($price->label(), 120),
            'status' => 'ACTIVE',
            'billing_cycles' => [[
                'frequency' => ['interval_unit' => strtoupper($unit), 'interval_count' => $count],
                'tenure_type' => 'REGULAR',
                'sequence' => 1,
                'total_cycles' => 0,
                'pricing_scheme' => [
                    'fixed_price' => [
                        'value' => $this->toPaypalAmount((float) $price->amount, $price->currency),
                        'currency_code' => $price->currency,
                    ],
                ],
            ]],
            'payment_preferences' => [
                'auto_bill_outstanding' => true,
                'payment_failure_threshold' => 3,
            ],
        ]);

        if (! $response?->successful()) {
            return $this->errorMessage($response);
        }

        return ['vendor_plan_id' => (string) $response->json('id'), 'vendor_product_id' => $productId];
    }

    public function subscribe(Subscription $subscription, Transaction $transaction, string $vendorPlanId): string|array
    {
        if (! $this->accessToken()) {
            return 'PayPal could not be reached. Please try again.';
        }

        $response = $this->send(PaymentTraceStepEnum::INITIALIZE, 'POST', '/v1/billing/subscriptions', [
            'plan_id' => $vendorPlanId,
            'custom_id' => $transaction->reference,
            'subscriber' => ['email_address' => $transaction->user->email],
            'application_context' => [
                'brand_name' => str()->limit((string) config('app.name'), 120),
                'user_action' => 'SUBSCRIBE_NOW',
                'shipping_preference' => 'NO_SHIPPING',
                'return_url' => $this->callbackUrl($transaction),
                'cancel_url' => $this->callbackUrl($transaction, cancelled: true),
            ],
        ], $transaction, $this->client()->withHeaders(['PayPal-Request-Id' => "subscribe-{$transaction->reference}"]));

        if (! $response?->successful()) {
            return $this->errorMessage($response);
        }

        $url = collect((array) $response->json('links'))->firstWhere('rel', 'approve')['href'] ?? null;

        return $url
            ? ['url' => (string) $url, 'gateway_id' => $response->json('id')]
            : 'PayPal did not return an approval link.';
    }

    /**
     * Active with a last payment is a paid first cycle. The sale behind it is
     * looked up so the PAYMENT.SALE.COMPLETED that follows is recognised as the
     * same money rather than recorded as a renewal.
     */
    public function verifySubscriptionCheckout(TransactionGateway $gateway): string|array
    {
        $data = $this->subscriptionDetails((string) $gateway->gateway_id, $gateway->transaction);

        if (\is_string($data)) {
            return $data;
        }

        $lastPayment = data_get($data, 'billing_info.last_payment');
        $status = match ((string) ($data['status'] ?? '')) {
            'ACTIVE' => $lastPayment ? StatusTransaction::CONFIRMED : StatusTransaction::PROCESSING,
            'CANCELLED', 'EXPIRED' => StatusTransaction::CANCELLED,
            default => StatusTransaction::PROCESSING,
        };

        $saleId = null;

        if ($status->isConfirmed()) {
            $sales = $this->send(
                PaymentTraceStepEnum::VERIFY,
                'GET',
                '/v1/billing/subscriptions/'.rawurlencode((string) $gateway->gateway_id).'/transactions',
                [
                    'start_time' => now()->subDays(2)->toIso8601ZuluString(),
                    'end_time' => now()->addDay()->toIso8601ZuluString(),
                ],
                $gateway->transaction,
            );

            $saleId = collect((array) $sales?->json('transactions'))->firstWhere('status', 'COMPLETED')['id'] ?? null;
        }

        return [
            ...$this->verification(
                $status,
                isset($lastPayment['amount']['value']) ? (float) $lastPayment['amount']['value'] : null,
                $lastPayment['amount']['currency_code'] ?? null,
                $saleId,
                'paypal',
                $this->parseDate($lastPayment['time'] ?? null),
                $data['status'] ?? null,
                $data,
            ),
            'subscription_id' => $data['id'] ?? $gateway->gateway_id,
            'customer_id' => data_get($data, 'subscriber.payer_id'),
        ];
    }

    public function fetchSubscription(Subscription $subscription): string|array
    {
        if (! $subscription->vendor_subscription_id) {
            return 'PayPal has not reported a subscription id for this yet.';
        }

        $data = $this->subscriptionDetails($subscription->vendor_subscription_id);

        if (\is_string($data)) {
            return $data;
        }

        return $this->subscriptionState(
            match ((string) ($data['status'] ?? '')) {
                'ACTIVE' => StatusSubscription::ACTIVE,
                'SUSPENDED' => StatusSubscription::PAST_DUE,
                'CANCELLED' => StatusSubscription::CANCELLED,
                'EXPIRED' => StatusSubscription::EXPIRED,
                default => StatusSubscription::PENDING,
            },
            $data['id'] ?? null,
            $this->parseDate(data_get($data, 'billing_info.next_billing_time')),
            ($data['status'] ?? null) === 'CANCELLED',
        );
    }

    /**
     * PayPal has no "at period end": a cancelled subscription stops billing at
     * once, and the time already paid for runs out on the kit's own clock.
     */
    public function cancelSubscription(Subscription $subscription, bool $atPeriodEnd = true): ?string
    {
        if (! $subscription->vendor_subscription_id || ! $this->accessToken()) {
            return 'PayPal could not be asked to cancel this subscription.';
        }

        $response = $this->send(
            PaymentTraceStepEnum::SUBSCRIPTION,
            'POST',
            '/v1/billing/subscriptions/'.rawurlencode($subscription->vendor_subscription_id).'/cancel',
            ['reason' => 'Cancelled by the subscriber.'],
        );

        return $response?->successful() ? null : $this->errorMessage($response);
    }

    public function parseSubscriptionEvent(PaymentWebhook $webhook): ?array
    {
        $resource = (array) data_get($webhook->payload, 'resource', []);
        $type = (string) $webhook->event_name;

        return match (true) {
            $type === 'PAYMENT.SALE.COMPLETED' => $this->subscriptionEvent(
                SubscriptionEventEnum::RENEWED,
                $resource['billing_agreement_id'] ?? null,
                paymentId: $resource['id'] ?? null,
            ),
            $type === 'BILLING.SUBSCRIPTION.PAYMENT.FAILED' => $this->subscriptionEvent(SubscriptionEventEnum::PAYMENT_FAILED, $resource['id'] ?? null),
            str_starts_with($type, 'BILLING.SUBSCRIPTION.') => $this->subscriptionEvent(SubscriptionEventEnum::CHANGED, $resource['id'] ?? null),
            default => null,
        };
    }

    public function verifyRenewal(Subscription $subscription, string $paymentId): string|array
    {
        if (! $this->accessToken()) {
            return 'PayPal could not be reached. Please try again.';
        }

        $response = $this->send(PaymentTraceStepEnum::VERIFY, 'GET', '/v1/payments/sale/'.rawurlencode($paymentId));

        if (! $response?->successful()) {
            return $this->errorMessage($response);
        }

        $data = (array) $response->json();

        return $this->verification(
            match ((string) ($data['state'] ?? '')) {
                'completed' => StatusTransaction::CONFIRMED,
                'denied', 'refunded', 'reversed' => StatusTransaction::FAILED,
                default => StatusTransaction::PROCESSING,
            },
            isset($data['amount']['total']) ? (float) $data['amount']['total'] : null,
            $data['amount']['currency'] ?? null,
            $data['id'] ?? null,
            'paypal',
            $this->parseDate($data['create_time'] ?? null),
            raw: $data,
        );
    }

    // Tools

    /**
     * @return array<string, mixed>|string
     */
    protected function subscriptionDetails(string $id, ?Transaction $transaction = null): array|string
    {
        if ($id === '' || ! $this->accessToken()) {
            return 'PayPal could not be asked about this subscription.';
        }

        $response = $this->send(PaymentTraceStepEnum::SUBSCRIPTION, 'GET', '/v1/billing/subscriptions/'.rawurlencode($id), transaction: $transaction);

        return $response?->successful() ? (array) $response->json() : $this->errorMessage($response);
    }

    /**
     * An OAuth token, cached until shortly before PayPal says it expires. Null
     * when PayPal could not be reached or refused the credentials.
     */
    protected function accessToken(): ?string
    {
        $key = $this->tokenCacheKey();

        if ($token = Cache::get($key)) {
            return $token;
        }

        $response = $this->send(
            PaymentTraceStepEnum::AUTHENTICATE,
            'POST',
            '/v1/oauth2/token',
            ['grant_type' => 'client_credentials'],
            request: $this->http()
                ->asForm()
                ->baseUrl($this->baseUrl())
                ->withBasicAuth((string) $this->config('client_id'), (string) $this->config('secret')),
        );

        $token = $response?->successful() ? $response->json('access_token') : null;

        if (! $token) {
            return null;
        }

        Cache::put($key, $token, now()->addSeconds(max(60, (int) $response->json('expires_in', 3600) - 60)));

        return $token;
    }

    /**
     * Keyed by the client id, so switching sandbox for live credentials never
     * reuses a token issued to the other.
     */
    protected function tokenCacheKey(): string
    {
        return 'payments:paypal:token:'.md5((string) $this->config('client_id').($this->isSandbox() ? 'sandbox' : 'live'));
    }

    /**
     * @param  array<string, mixed>  $order
     * @return array{status: StatusTransaction, amount: ?float, currency: ?string, payment_id: ?string, channel: ?string, paid_at: ?CarbonInterface, message: ?string, raw: array<string, mixed>}
     */
    protected function fromOrder(array $order): array
    {
        $capture = $order['purchase_units'][0]['payments']['captures'][0] ?? null;

        $status = match ($order['status'] ?? null) {
            'COMPLETED' => match ($capture['status'] ?? 'COMPLETED') {
                'COMPLETED' => StatusTransaction::CONFIRMED,
                'DECLINED', 'FAILED' => StatusTransaction::FAILED,
                default => StatusTransaction::PROCESSING,
            },
            'VOIDED' => StatusTransaction::CANCELLED,
            default => StatusTransaction::PROCESSING,
        };

        $amount = $capture['amount'] ?? $order['purchase_units'][0]['amount'] ?? null;

        return $this->verification(
            $status,
            isset($amount['value']) ? (float) $amount['value'] : null,
            $amount['currency_code'] ?? null,
            $capture['id'] ?? null,
            'paypal',
            $this->parseDate($capture['create_time'] ?? null),
            $capture['status_details']['reason'] ?? null,
            $order,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{status: StatusTransaction, refund_id: ?string, message: ?string, raw: array<string, mixed>}
     */
    protected function fromRefund(array $data): array
    {
        return $this->refundResult(
            match ((string) ($data['status'] ?? '')) {
                'COMPLETED' => StatusTransaction::CONFIRMED,
                'FAILED', 'CANCELLED' => StatusTransaction::FAILED,
                default => StatusTransaction::PROCESSING,
            },
            $data['id'] ?? null,
            $data['status_details']['reason'] ?? $data['status'] ?? null,
            $data,
        );
    }

    /**
     * The capture a refund webhook belongs to, from the refund resource's "up"
     * link — a refund carries no reference of ours.
     */
    protected function captureFromLinks(array $links): ?string
    {
        $href = collect($links)->first(fn ($link) => ($link['rel'] ?? null) === 'up')['href'] ?? null;

        return $href && str_contains($href, '/captures/') ? basename(parse_url($href, PHP_URL_PATH)) : null;
    }

    protected function toPaypalAmount(float $amount, string $currency): string
    {
        $decimals = \in_array(strtoupper($currency), self::ZERO_DECIMAL, true) ? 0 : 2;

        return number_format($amount, $decimals, '.', '');
    }
}
