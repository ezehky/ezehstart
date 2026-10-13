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
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionPlanVendor;
use App\Models\Transaction;
use App\Models\TransactionGateway;
use Carbon\CarbonInterface;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;

/**
 * Paystack, over its REST API.
 *
 * Amounts go out in the smallest unit (kobo). Our transaction reference is
 * Paystack's reference, so verification and webhooks both find a row by it.
 * Webhooks are signed with an HMAC-SHA512 of the raw body, keyed with the
 * secret key — there is no separate webhook secret.
 *
 * Subscriptions: a plan is a Paystack plan; the first payment is an ordinary
 * checkout with the plan_code on it, and Paystack starts the subscription when
 * that charge succeeds. Renewals arrive as charge.success with a plan and a
 * reference of Paystack's own, which is what tells them apart from the first.
 */
#[Singleton]
class PaystackGatewayService extends BasePaymentGateway implements SupportsSubscriptions
{
    protected const BASE_URL = 'https://api.paystack.co';

    public function vendor(): PaymentVendorEnum
    {
        return PaymentVendorEnum::PAYSTACK;
    }

    protected function requiredKeys(): array
    {
        return ['secret'];
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
        $response = $this->send(PaymentTraceStepEnum::INITIALIZE, 'POST', '/transaction/initialize', [
            'email' => $transaction->user->email,
            'amount' => $this->toMinor((float) $transaction->amount),
            'currency' => $this->currency(),
            'reference' => $transaction->reference,
            'callback_url' => $this->callbackUrl($transaction),
            'metadata' => [
                'transaction_reference' => $transaction->reference,
                'cancel_action' => $this->callbackUrl($transaction, cancelled: true),
            ],
            ...$extra,
        ], $transaction);

        if (! $response?->successful() || ! $response->json('status')) {
            return $this->errorMessage($response);
        }

        return [
            'url' => (string) $response->json('data.authorization_url'),
            'gateway_id' => $response->json('data.reference'),
        ];
    }

    public function verify(TransactionGateway $gateway): string|array
    {
        return $this->verifyReference($gateway->transaction->reference, $gateway->transaction);
    }

    /**
     * @return string|array{status: StatusTransaction, amount: ?float, currency: ?string, payment_id: ?string, channel: ?string, paid_at: ?CarbonInterface, message: ?string, raw: array<string, mixed>}
     */
    protected function verifyReference(string $reference, ?Transaction $transaction = null): string|array
    {
        $response = $this->send(PaymentTraceStepEnum::VERIFY, 'GET', '/transaction/verify/'.rawurlencode($reference), transaction: $transaction);

        if (! $response?->successful() || ! $response->json('status')) {
            return $this->errorMessage($response);
        }

        $data = (array) $response->json('data');

        return $this->verification(
            $this->status((string) ($data['status'] ?? '')),
            $this->fromMinor($data['amount'] ?? null),
            $data['currency'] ?? null,
            $data['id'] ?? null,
            $data['channel'] ?? null,
            $this->parseDate($data['paid_at'] ?? null),
            $data['gateway_response'] ?? null,
            $data,
        );
    }

    public function verifyWebhook(Request $request): bool
    {
        $signature = (string) $request->header('x-paystack-signature');

        return $signature !== ''
            && hash_equals(hash_hmac('sha512', $request->getContent(), (string) $this->config('secret')), $signature);
    }

    public function parseWebhook(Request $request): array
    {
        $event = (string) $request->input('event');

        return [
            'event_key' => $this->bodyKey($request),
            'event_name' => $event ?: null,
            'topic' => match (true) {
                str_starts_with($event, 'charge.') => PaymentWebhookTopicEnum::CHARGE,
                str_starts_with($event, 'refund.') => PaymentWebhookTopicEnum::REFUND,
                str_starts_with($event, 'subscription.'), str_starts_with($event, 'invoice.') => PaymentWebhookTopicEnum::SUBSCRIPTION,
                default => PaymentWebhookTopicEnum::OTHER,
            },
            'reference' => $request->input('data.reference') ?? $request->input('data.transaction_reference'),
        ];
    }

    public function ping(): ?string
    {
        $response = $this->send(PaymentTraceStepEnum::HEALTH, 'GET', '/balance');

        return $response?->successful() ? null : $this->errorMessage($response);
    }

    public function refund(TransactionGateway $original, Transaction $refund, float $amount, ?string $reason = null): string|array
    {
        $response = $this->send(PaymentTraceStepEnum::REFUND, 'POST', '/refund', array_filter([
            'transaction' => $original->transaction->reference,
            'amount' => $this->toMinor($amount),
            'merchant_note' => $reason,
        ]), $refund);

        if (! $response?->successful() || ! $response->json('status')) {
            return $this->errorMessage($response);
        }

        return $this->fromRefund((array) $response->json('data'));
    }

    public function refundStatus(TransactionGateway $refund): string|array
    {
        if (! $refund->gateway_id) {
            return 'Paystack never acknowledged this refund.';
        }

        $response = $this->send(PaymentTraceStepEnum::REFUND, 'GET', '/refund/'.rawurlencode($refund->gateway_id), transaction: $refund->transaction);

        if (! $response?->successful() || ! $response->json('status')) {
            return $this->errorMessage($response);
        }

        return $this->fromRefund((array) $response->json('data'));
    }

    // Subscriptions

    public function syncPlan(SubscriptionPlan $plan, ?SubscriptionPlanVendor $existing = null): string|array
    {
        $response = $this->send(PaymentTraceStepEnum::SUBSCRIPTION, 'POST', '/plan', [
            'name' => $plan->name,
            'amount' => $this->toMinor((float) $plan->amount),
            'interval' => $plan->billing_interval->paystackInterval(),
            'currency' => $plan->currency,
            'description' => str()->limit((string) $plan->description, 200),
        ]);

        if (! $response?->successful() || ! $response->json('status')) {
            return $this->errorMessage($response);
        }

        return ['vendor_plan_id' => (string) $response->json('data.plan_code'), 'vendor_product_id' => null];
    }

    public function subscribe(Subscription $subscription, Transaction $transaction, string $vendorPlanId): string|array
    {
        return $this->openCheckout($transaction, ['plan' => $vendorPlanId]);
    }

    public function verifySubscriptionCheckout(TransactionGateway $gateway): string|array
    {
        $result = $this->verify($gateway);

        if (\is_string($result)) {
            return $result;
        }

        // Paystack starts the subscription after the charge, so there is no id
        // for it yet — the customer is what finds it afterwards.
        return [
            ...$result,
            'subscription_id' => null,
            'customer_id' => isset($result['raw']['customer']['id']) ? (string) $result['raw']['customer']['id'] : null,
        ];
    }

    public function fetchSubscription(Subscription $subscription): string|array
    {
        $data = $subscription->vendor_subscription_id
            ? $this->subscriptionByCode($subscription->vendor_subscription_id)
            : $this->subscriptionForCustomer($subscription);

        if (! \is_array($data)) {
            return $data ?? 'Paystack has no subscription for this customer and plan yet.';
        }

        $status = (string) ($data['status'] ?? '');

        return $this->subscriptionState(
            match ($status) {
                'active' => StatusSubscription::ACTIVE,
                'non-renewing' => StatusSubscription::CANCELLED,
                'attention' => StatusSubscription::PAST_DUE,
                'cancelled', 'complete', 'completed' => StatusSubscription::EXPIRED,
                default => StatusSubscription::PENDING,
            },
            $data['subscription_code'] ?? null,
            $this->parseDate($data['next_payment_date'] ?? null),
            $status === 'non-renewing',
            $data['email_token'] ?? null,
        );
    }

    /**
     * Paystack's "disable" stops the renewals; there is no immediate end that
     * refunds the period, so the paid time runs out on its own either way.
     */
    public function cancelSubscription(Subscription $subscription, bool $atPeriodEnd = true): ?string
    {
        $code = $subscription->vendor_subscription_id;
        $token = $subscription->vendor_token;

        if (! $code || ! $token) {
            $state = $this->fetchSubscription($subscription);

            if (\is_string($state)) {
                return $state;
            }

            $code ??= $state['vendor_subscription_id'];
            $token ??= $state['token'];
        }

        $response = $this->send(PaymentTraceStepEnum::SUBSCRIPTION, 'POST', '/subscription/disable', [
            'code' => $code,
            'token' => $token,
        ]);

        return $response?->successful() && $response->json('status') ? null : $this->errorMessage($response);
    }

    public function parseSubscriptionEvent(PaymentWebhook $webhook): ?array
    {
        $data = (array) data_get($webhook->payload, 'data', []);
        $email = data_get($data, 'customer.email');

        return match ((string) $webhook->event_name) {
            // A charge with a plan on it and a reference of Paystack's own: a
            // renewal. The first charge carries our reference and never gets here.
            'charge.success' => data_get($data, 'plan.plan_code')
                ? $this->subscriptionEvent(SubscriptionEventEnum::RENEWED, null, $email, data_get($data, 'plan.plan_code'), $data['reference'] ?? null)
                : null,
            'invoice.payment_failed' => $this->subscriptionEvent(
                SubscriptionEventEnum::PAYMENT_FAILED,
                data_get($data, 'subscription.subscription_code'),
                $email,
            ),
            'subscription.create', 'subscription.not_renew', 'subscription.disable' => $this->subscriptionEvent(
                SubscriptionEventEnum::CHANGED,
                $data['subscription_code'] ?? null,
                $email,
                data_get($data, 'plan.plan_code'),
                periodEndsAt: $this->parseDate($data['next_payment_date'] ?? null),
                token: $data['email_token'] ?? null,
            ),
            default => null,
        };
    }

    public function verifyRenewal(Subscription $subscription, string $paymentId): string|array
    {
        return $this->verifyReference($paymentId);
    }

    // Tools

    /**
     * @return array<string, mixed>|string|null
     */
    protected function subscriptionByCode(string $code): array|string|null
    {
        $response = $this->send(PaymentTraceStepEnum::SUBSCRIPTION, 'GET', '/subscription/'.rawurlencode($code));

        if (! $response?->successful() || ! $response->json('status')) {
            return $this->errorMessage($response);
        }

        return (array) $response->json('data');
    }

    /**
     * The newest subscription this customer holds on the plan they signed up
     * to — how a subscription Paystack started on its own is found.
     *
     * @return array<string, mixed>|string|null
     */
    protected function subscriptionForCustomer(Subscription $subscription): array|string|null
    {
        if (! $subscription->vendor_customer_id || ! $subscription->vendor_plan_id) {
            return null;
        }

        $response = $this->send(PaymentTraceStepEnum::SUBSCRIPTION, 'GET', '/subscription', [
            'customer' => $subscription->vendor_customer_id,
            'perPage' => 50,
        ]);

        if (! $response?->successful() || ! $response->json('status')) {
            return $this->errorMessage($response);
        }

        return collect((array) $response->json('data'))
            ->filter(fn ($row) => data_get($row, 'plan.plan_code') === $subscription->vendor_plan_id)
            ->sortByDesc('createdAt')
            ->first();
    }

    /**
     * Paystack's statuses, onto ours. "abandoned" is not final on Paystack's
     * side — the customer can come back to the same checkout — so it stays
     * PROCESSING until the reconcile sweep decides it has been long enough.
     * A reversed charge never landed, which for a checkout is a failure.
     */
    /**
     * @param  array<string, mixed>  $data
     * @return array{status: StatusTransaction, refund_id: ?string, message: ?string, raw: array<string, mixed>}
     */
    protected function fromRefund(array $data): array
    {
        return $this->refundResult(
            match ((string) ($data['status'] ?? '')) {
                'processed' => StatusTransaction::CONFIRMED,
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
        return match ($status) {
            'success' => StatusTransaction::CONFIRMED,
            'failed', 'reversed' => StatusTransaction::FAILED,
            default => StatusTransaction::PROCESSING,
        };
    }
}
