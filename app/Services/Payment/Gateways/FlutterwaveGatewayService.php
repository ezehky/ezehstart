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
 * Flutterwave Standard, over the v3 API.
 *
 * Amounts go out in major units. Our reference is Flutterwave's tx_ref, and
 * verification is by tx_ref so the callback never has to trust the
 * transaction_id the browser carries back. Webhooks carry the dashboard's
 * "secret hash" in a verif-hash header, compared as-is.
 *
 * Subscriptions: a plan is a Flutterwave payment plan, and the first checkout
 * carries its id. Renewal charges come in as charge.completed with a tx_ref
 * that is not ours; they are matched to a subscription by the customer's
 * email and checked by Flutterwave's own transaction id.
 */
#[Singleton]
class FlutterwaveGatewayService extends BasePaymentGateway implements SupportsSubscriptions
{
    protected const BASE_URL = 'https://api.flutterwave.com/v3';

    public function vendor(): PaymentVendorEnum
    {
        return PaymentVendorEnum::FLUTTERWAVE;
    }

    protected function requiredKeys(): array
    {
        return ['secret', 'webhook_hash'];
    }

    protected function client(): PendingRequest
    {
        return $this->http()
            ->baseUrl(self::BASE_URL)
            ->withToken((string) $this->config('secret'));
    }

    // Actions

    public function initialize(Transaction $transaction): string|array
    {
        return $this->openCheckout($transaction);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return string|array{url: string, gateway_id: ?string}
     */
    protected function openCheckout(Transaction $transaction, array $extra = []): string|array
    {
        $response = $this->send(PaymentTraceStepEnum::INITIALIZE, 'POST', '/payments', [
            ...$extra,
            'tx_ref' => $transaction->reference,
            'amount' => round((float) $transaction->amount, 2),
            'currency' => $this->currency(),
            'redirect_url' => $this->callbackUrl($transaction),
            'customer' => [
                'email' => $transaction->user->email,
                'name' => $transaction->user->name,
            ],
            'customizations' => [
                'title' => config('app.name'),
                'description' => $transaction->description,
            ],
            'meta' => ['transaction_reference' => $transaction->reference],
        ], $transaction);

        if (! $response?->successful() || $response->json('status') !== 'success') {
            return $this->errorMessage($response);
        }

        return [
            'url' => (string) $response->json('data.link'),
            'gateway_id' => null,
        ];
    }

    public function verify(TransactionGateway $gateway): string|array
    {
        $response = $this->send(PaymentTraceStepEnum::VERIFY, 'GET', '/transactions/verify_by_reference', [
            'tx_ref' => $gateway->transaction->reference,
        ], $gateway->transaction);

        // No charge was ever attempted against the reference — the customer
        // opened the checkout and left. Flutterwave answers that with a 4xx.
        if ($response?->clientError() && ! $response->json('data')) {
            return $this->verification(StatusTransaction::PROCESSING, message: $this->errorMessage($response));
        }

        if (! $response?->successful() || $response->json('status') !== 'success') {
            return $this->errorMessage($response);
        }

        $data = (array) $response->json('data');

        return $this->verification(
            $this->status((string) ($data['status'] ?? '')),
            isset($data['amount']) ? (float) $data['amount'] : null,
            $data['currency'] ?? null,
            $data['id'] ?? null,
            $data['payment_type'] ?? null,
            $this->parseDate($data['created_at'] ?? null),
            $data['processor_response'] ?? null,
            $data,
        );
    }

    public function verifyWebhook(Request $request): bool
    {
        $hash = (string) $request->header('verif-hash');

        return $hash !== '' && hash_equals((string) $this->config('webhook_hash'), $hash);
    }

    public function parseWebhook(Request $request): array
    {
        $event = (string) ($request->input('event') ?? $request->input('event.type'));

        return [
            // Flutterwave sends no event id, but each charge has its own id and a
            // status, which is what a retry repeats.
            'event_key' => $this->bodyKey($request),
            'event_name' => $event ?: null,
            'topic' => match (true) {
                str_starts_with($event, 'charge.') => PaymentWebhookTopicEnum::CHARGE,
                str_contains($event, 'refund') => PaymentWebhookTopicEnum::REFUND,
                str_starts_with($event, 'subscription.') => PaymentWebhookTopicEnum::SUBSCRIPTION,
                default => PaymentWebhookTopicEnum::OTHER,
            },
            'reference' => $request->input('data.tx_ref') ?? $request->input('data.txRef'),
        ];
    }

    public function ping(): ?string
    {
        $response = $this->send(PaymentTraceStepEnum::HEALTH, 'GET', '/balances');

        return $response?->successful() ? null : $this->errorMessage($response);
    }

    public function refund(TransactionGateway $original, Transaction $refund, float $amount, ?string $reason = null): string|array
    {
        if (! $paymentId = $this->paymentId($original)) {
            return 'This payment was never verified with Flutterwave, so there is no charge to refund.';
        }

        $response = $this->send(PaymentTraceStepEnum::REFUND, 'POST', '/transactions/'.rawurlencode($paymentId).'/refund', array_filter([
            'amount' => round($amount, 2),
            'comments' => $reason,
        ]), $refund);

        if (! $response?->successful() || $response->json('status') !== 'success') {
            return $this->errorMessage($response);
        }

        return $this->fromRefund((array) $response->json('data'));
    }

    public function refundStatus(TransactionGateway $refund): string|array
    {
        if (! $refund->gateway_id) {
            return 'Flutterwave never acknowledged this refund.';
        }

        $response = $this->send(PaymentTraceStepEnum::REFUND, 'GET', '/refunds/'.rawurlencode($refund->gateway_id), transaction: $refund->transaction);

        if (! $response?->successful() || $response->json('status') !== 'success') {
            return $this->errorMessage($response);
        }

        return $this->fromRefund((array) $response->json('data'));
    }

    // Subscriptions

    public function syncPlan(SubscriptionPlanPrice $price, ?SubscriptionPlanVendor $existing = null): string|array
    {
        $response = $this->send(PaymentTraceStepEnum::SUBSCRIPTION, 'POST', '/payment-plans', [
            'name' => $price->label(),
            'amount' => round((float) $price->amount, 2),
            'interval' => $price->billing_interval->flutterwaveInterval(),
            'currency' => $price->currency,
        ]);

        if (! $response?->successful() || $response->json('status') !== 'success') {
            return $this->errorMessage($response);
        }

        return ['vendor_plan_id' => (string) $response->json('data.id'), 'vendor_product_id' => null];
    }

    public function subscribe(Subscription $subscription, Transaction $transaction, string $vendorPlanId): string|array
    {
        return $this->openCheckout($transaction, ['payment_plan' => $vendorPlanId]);
    }

    public function verifySubscriptionCheckout(TransactionGateway $gateway): string|array
    {
        $result = $this->verify($gateway);

        if (\is_string($result)) {
            return $result;
        }

        // Flutterwave's subscriptions are listed by the customer's email, so
        // the email is the customer id worth keeping.
        return [
            ...$result,
            'subscription_id' => null,
            'customer_id' => data_get($result['raw'], 'customer.email') ?? $gateway->transaction->user->email,
        ];
    }

    public function fetchSubscription(Subscription $subscription): string|array
    {
        $response = $this->send(PaymentTraceStepEnum::SUBSCRIPTION, 'GET', '/subscriptions', array_filter([
            'email' => $subscription->vendor_customer_id ?? $subscription->user?->email,
            'plan' => $subscription->vendor_plan_id,
        ]));

        if (! $response?->successful() || $response->json('status') !== 'success') {
            return $this->errorMessage($response);
        }

        $row = collect((array) $response->json('data'))
            ->first(fn ($row) => $subscription->vendor_subscription_id
                ? (string) ($row['id'] ?? '') === $subscription->vendor_subscription_id
                : (string) data_get($row, 'plan') === (string) $subscription->vendor_plan_id);

        if (! $row) {
            return 'Flutterwave has no subscription for this customer and plan yet.';
        }

        // Flutterwave does not say when the next charge is due; the kit keeps
        // its own period end, advanced on every renewal.
        return $this->subscriptionState(
            match ((string) ($row['status'] ?? '')) {
                'active' => StatusSubscription::ACTIVE,
                'cancelled' => StatusSubscription::CANCELLED,
                default => StatusSubscription::PENDING,
            },
            $row['id'] ?? null,
            cancelAtPeriodEnd: ($row['status'] ?? null) === 'cancelled',
        );
    }

    public function cancelSubscription(Subscription $subscription, bool $atPeriodEnd = true): ?string
    {
        $id = $subscription->vendor_subscription_id;

        if (! $id) {
            $state = $this->fetchSubscription($subscription);

            if (\is_string($state)) {
                return $state;
            }

            $id = $state['vendor_subscription_id'];
        }

        $response = $this->send(PaymentTraceStepEnum::SUBSCRIPTION, 'PUT', '/subscriptions/'.rawurlencode((string) $id).'/cancel');

        return $response?->successful() && $response->json('status') === 'success' ? null : $this->errorMessage($response);
    }

    public function parseSubscriptionEvent(PaymentWebhook $webhook): ?array
    {
        $payload = (array) $webhook->payload;
        $data = (array) data_get($payload, 'data', []);
        $email = data_get($data, 'customer.email');

        return match ((string) $webhook->event_name) {
            'charge.completed' => strtolower((string) ($data['status'] ?? '')) === 'successful'
                ? $this->subscriptionEvent(
                    SubscriptionEventEnum::RENEWED,
                    null,
                    $email,
                    $data['payment_plan'] ?? data_get($data, 'plan.id'),
                    $data['id'] ?? null,
                )
                : null,
            'subscription.cancelled' => $this->subscriptionEvent(
                SubscriptionEventEnum::CHANGED,
                $data['id'] ?? null,
                $email,
                data_get($data, 'plan.id'),
            ),
            default => null,
        };
    }

    public function verifyRenewal(Subscription $subscription, string $paymentId): string|array
    {
        $response = $this->send(PaymentTraceStepEnum::VERIFY, 'GET', '/transactions/'.rawurlencode($paymentId).'/verify');

        if (! $response?->successful() || $response->json('status') !== 'success') {
            return $this->errorMessage($response);
        }

        $data = (array) $response->json('data');

        return $this->verification(
            $this->status((string) ($data['status'] ?? '')),
            isset($data['amount']) ? (float) $data['amount'] : null,
            $data['currency'] ?? null,
            $data['id'] ?? null,
            $data['payment_type'] ?? null,
            $this->parseDate($data['created_at'] ?? null),
            $data['processor_response'] ?? null,
            $data,
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
            match (strtolower((string) ($data['status'] ?? ''))) {
                'completed', 'successful' => StatusTransaction::CONFIRMED,
                'failed' => StatusTransaction::FAILED,
                default => StatusTransaction::PROCESSING,
            },
            $data['id'] ?? null,
            $data['status'] ?? null,
            $data,
        );
    }

    protected function status(string $status): StatusTransaction
    {
        return match (strtolower($status)) {
            'successful' => StatusTransaction::CONFIRMED,
            'failed' => StatusTransaction::FAILED,
            'cancelled' => StatusTransaction::CANCELLED,
            default => StatusTransaction::PROCESSING,
        };
    }
}
