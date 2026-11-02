<?php

namespace App\Services\Payment\Gateways;

use App\Contracts\BasePaymentGateway;
use App\Enums\PaymentTraceStepEnum;
use App\Enums\PaymentVendorEnum;
use App\Enums\PaymentWebhookTopicEnum;
use App\Enums\StatusTransaction;
use App\Models\Transaction;
use App\Models\TransactionGateway;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;

/**
 * Bachs checkout — cards, NGN bank transfer, mobile money and stablecoins in
 * one hosted session.
 *
 * Amounts go out as decimal strings in major units, never kobo or cents, and
 * the checkout is priced ad hoc (`pricing`) so nothing has to be created on
 * Bachs first. Our reference is the session's reference; the session id is the
 * gateway id, and the payment it collected (`charge.payment_id`) is what a
 * refund is made against.
 *
 * The key decides the host: an sk_live_ key talks to the live API and anything
 * else to the sandbox, because a key only works on its own environment.
 *
 * One-off payments only. Bachs starts a subscription from a checkout for a
 * recurring product made on Bachs, which is a different shape from the plans
 * the kit syncs, so it is never offered for a plan.
 */
#[Singleton]
class BachsGatewayService extends BasePaymentGateway
{
    protected const LIVE_URL = 'https://api.bachs.io/v1';

    protected const SANDBOX_URL = 'https://sandbox-api.bachs.io/v1';

    /** How old a signed webhook may be before it is refused as a replay. */
    protected const WEBHOOK_TOLERANCE_SECONDS = 300;

    public function vendor(): PaymentVendorEnum
    {
        return PaymentVendorEnum::BACHS;
    }

    /**
     * The secret key alone opens the gateway. The webhook secret only exists once
     * an endpoint is registered on Bachs, which a local .test site cannot have;
     * without it every webhook is refused and payments are confirmed by the
     * callback and the reconcile sweep, both of which ask the API.
     */
    protected function requiredKeys(): array
    {
        return app()->isProduction() ? ['secret', 'webhook_secret'] : ['secret'];
    }

    protected function client(): PendingRequest
    {
        $secret = (string) $this->config('secret');

        return $this->http()
            ->baseUrl(str_starts_with($secret, 'sk_live_') ? self::LIVE_URL : self::SANDBOX_URL)
            ->withToken($secret);
    }

    // Actions

    public function initialize(Transaction $transaction): string|array
    {
        $response = $this->send(PaymentTraceStepEnum::INITIALIZE, 'POST', '/checkout-sessions', [
            'pricing' => [
                'currency' => $this->currency(),
                'amount' => number_format((float) $transaction->amount, 2, '.', ''),
            ],
            'customer' => array_filter([
                'email' => $transaction->user->email,
                'name' => $transaction->user->name,
            ]),
            'reference' => $transaction->reference,
            'success_url' => $this->callbackUrl($transaction),
            'cancel_url' => $this->callbackUrl($transaction, cancelled: true),
            'expires_in_minutes' => min(1440, $this->expiresInMinutes()),
            'metadata' => [
                'transaction_reference' => $transaction->reference,
                'description' => str()->limit((string) $transaction->description, 200),
            ],
        ], $transaction);

        if (! $response?->successful() || blank($response->json('checkout_url'))) {
            return $this->errorMessage($response);
        }

        return [
            'url' => (string) $response->json('checkout_url'),
            'gateway_id' => $response->json('checkout_id'),
        ];
    }

    /**
     * The session, not the browser's word: a completed session carries the
     * payment it collected as `charge`. A failed attempt leaves the session
     * open for the customer to try again, so only an expired or cancelled
     * session is final without a payment.
     */
    public function verify(TransactionGateway $gateway): string|array
    {
        if (blank($gateway->gateway_id)) {
            return 'Bachs has no checkout on record for this payment.';
        }

        $response = $this->send(PaymentTraceStepEnum::VERIFY, 'GET', '/checkout-sessions/'.rawurlencode((string) $gateway->gateway_id), transaction: $gateway->transaction);

        if (! $response?->successful()) {
            return $this->errorMessage($response);
        }

        $data = (array) $response->json();
        $charge = (array) ($data['charge'] ?? []);
        $chargeStatus = (string) ($charge['status'] ?? '');

        $status = match (true) {
            // Underpaid still moved money: it is reported with what arrived,
            // and the short amount is held for review like any vendor's.
            \in_array($chargeStatus, ['succeeded', 'accepted', 'overpaid', 'underpaid'], true) => StatusTransaction::CONFIRMED,
            \in_array($data['status'] ?? null, ['expired', 'cancelled'], true) => StatusTransaction::CANCELLED,
            default => StatusTransaction::PROCESSING,
        };

        $paid = $status === StatusTransaction::CONFIRMED;
        $amount = $charge['amount_paid'] ?? $charge['amount'] ?? null;

        return $this->verification(
            $status,
            $paid && $amount !== null ? (float) $amount : null,
            $charge['currency'] ?? $data['currency'] ?? null,
            $charge['payment_id'] ?? null,
            $charge['payment_method'] ?? $data['payment_method'] ?? null,
            $paid ? $this->parseDate($charge['completed_at'] ?? $data['completed_at'] ?? null) ?? now() : null,
            $chargeStatus ?: ($data['status'] ?? null),
            $data,
        );
    }

    /**
     * An HMAC-SHA256 of "{timestamp}.{raw body}" with the endpoint's signing
     * secret. The V2 header carries the timestamp beside one signature, or two
     * while a secret is being rotated; the older pair of headers is accepted
     * for an endpoint that still sends only those.
     */
    public function verifyWebhook(Request $request): bool
    {
        $secret = (string) $this->config('webhook_secret');

        if ($secret === '') {
            return false;
        }

        $timestamp = (string) $request->header('X-Bachs-Timestamp');
        $signatures = array_filter([(string) $request->header('X-Bachs-Signature')]);

        foreach (explode(',', (string) $request->header('X-Bachs-Signature-V2')) as $part) {
            [$name, $value] = array_pad(explode('=', trim($part), 2), 2, '');

            match ($name) {
                't' => $timestamp = $value,
                'v1' => $signatures[] = $value,
                default => null,
            };
        }

        if (! ctype_digit($timestamp) || $signatures === [] || abs(time() - (int) $timestamp) > self::WEBHOOK_TOLERANCE_SECONDS) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    public function parseWebhook(Request $request): array
    {
        $event = (string) $request->input('type');

        return [
            'event_key' => (string) ($request->input('id') ?: $this->bodyKey($request)),
            'event_name' => $event ?: null,
            'topic' => match (true) {
                str_starts_with($event, 'collection.'), str_starts_with($event, 'checkout.') => PaymentWebhookTopicEnum::CHARGE,
                str_starts_with($event, 'refund.') => PaymentWebhookTopicEnum::REFUND,
                default => PaymentWebhookTopicEnum::OTHER,
            },
            // A refund event's reference is the refund's own, which is ours
            // when it was made from the site. A payment event echoes the
            // session's reference, or failing that the session id.
            'reference' => $request->input('data.reference') ?? $request->input('data.checkout_id'),
        ];
    }

    /**
     * Any read-scoped call proves the key; the balance is the cheapest.
     */
    public function ping(): ?string
    {
        $response = $this->send(PaymentTraceStepEnum::HEALTH, 'GET', '/balances');

        return $response?->successful() ? null : $this->errorMessage($response);
    }

    public function refund(TransactionGateway $original, Transaction $refund, float $amount, ?string $reason = null): string|array
    {
        $chargeId = $this->paymentId($original);

        if (! $chargeId) {
            return 'Bachs has not reported the payment this refund is against.';
        }

        $response = $this->send(PaymentTraceStepEnum::REFUND, 'POST', '/refunds', array_filter([
            'charge_id' => $chargeId,
            'reference' => $refund->reference,
            'amount' => number_format($amount, 2, '.', ''),
            'reason' => $reason ? str()->limit($reason, 495) : null,
            // A retry of the same refund answers with the one already made.
            'idempotency_key' => $refund->reference,
        ]), $refund);

        if (! $response?->successful()) {
            return $this->errorMessage($response);
        }

        return $this->fromRefund((array) $response->json());
    }

    public function refundStatus(TransactionGateway $refund): string|array
    {
        if (blank($refund->gateway_id)) {
            return 'Bachs has not reported an id for this refund yet.';
        }

        $response = $this->send(PaymentTraceStepEnum::REFUND, 'GET', '/refunds/'.rawurlencode((string) $refund->gateway_id), transaction: $refund->transaction);

        if (! $response?->successful()) {
            return $this->errorMessage($response);
        }

        return $this->fromRefund((array) $response->json());
    }

    // Tools

    /**
     * Bachs answers an error as a flat object with the sentence in `detail`.
     */
    protected function errorMessage(?Response $response, string $fallback = 'The payment provider could not be reached. Please try again.'): string
    {
        $detail = $response?->json('detail');

        return \is_string($detail) && $detail !== '' ? $detail : parent::errorMessage($response, $fallback);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{status: StatusTransaction, refund_id: ?string, message: ?string, raw: array<string, mixed>}
     */
    protected function fromRefund(array $data): array
    {
        return $this->refundResult(
            match ((string) ($data['status'] ?? '')) {
                'success', 'paid' => StatusTransaction::CONFIRMED,
                'failed' => StatusTransaction::FAILED,
                default => StatusTransaction::PROCESSING,
            },
            $data['refund_id'] ?? null,
            $data['status'] ?? null,
            $data,
        );
    }
}
