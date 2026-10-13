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
 * OPay Cashier.
 *
 * Three different credentials for three different calls, which is the thing to
 * know about OPay:
 *
 * - Opening a cashier is authorised with the public key as a bearer token.
 * - Asking for a status is authorised with an HMAC-SHA512 of the exact JSON
 *   body, keyed with the secret key — so the body is encoded once and that
 *   string is both signed and sent.
 * - A callback is signed with an HMAC-SHA3-512 (not SHA-512) over a fixed
 *   template built from eight payload fields, keyed with the secret key.
 *
 * Amounts are in the smallest unit.
 */
#[Singleton]
class OpayGatewayService extends BasePaymentGateway
{
    /** OPay answers 00000 for success, inside a 200, whatever happened. */
    protected const SUCCESS_CODE = '00000';

    /** The code OPay returns for bad credentials. */
    protected const AUTH_FAILED_CODE = '02000';

    public function vendor(): PaymentVendorEnum
    {
        return PaymentVendorEnum::OPAY;
    }

    protected function requiredKeys(): array
    {
        return ['merchant_id', 'public', 'secret'];
    }

    /**
     * OPay's own pages disagree on the host — the cashier docs say
     * testapi/liveapi, the refund docs sandboxapi/api — so OPAY_BASE_URL can
     * pin whichever the merchant dashboard names.
     */
    protected function baseUrl(): string
    {
        if ($override = $this->config('base_url')) {
            return rtrim((string) $override, '/');
        }

        return $this->isSandbox() ? 'https://testapi.opaycheckout.com' : 'https://liveapi.opaycheckout.com';
    }

    protected function client(): PendingRequest
    {
        return $this->http()
            ->baseUrl($this->baseUrl())
            ->withHeaders(['MerchantId' => (string) $this->config('merchant_id')])
            ->withToken((string) $this->config('public'));
    }

    // Actions

    public function initialize(Transaction $transaction): string|array
    {
        $response = $this->send(PaymentTraceStepEnum::INITIALIZE, 'POST', '/api/v1/international/cashier/create', [
            'country' => (string) $this->config('country', 'NG'),
            'reference' => $transaction->reference,
            'amount' => [
                'total' => $this->toMinor((float) $transaction->amount),
                'currency' => $this->currency(),
            ],
            'returnUrl' => $this->callbackUrl($transaction),
            'cancelUrl' => $this->callbackUrl($transaction, cancelled: true),
            'callbackUrl' => $this->webhookUrl(),
            'expireAt' => $this->expiresInMinutes(),
            'userInfo' => [
                'userEmail' => $transaction->user->email,
                'userName' => $transaction->user->name,
                'userId' => (string) $transaction->user_id,
            ],
            'product' => [
                'name' => str()->limit($transaction->description, 100),
                'description' => str()->limit($transaction->description, 200),
            ],
        ], $transaction);

        if (! $response?->successful() || $response->json('code') !== self::SUCCESS_CODE) {
            return $this->errorMessage($response);
        }

        return [
            'url' => (string) $response->json('data.cashierUrl'),
            'gateway_id' => $response->json('data.orderNo'),
        ];
    }

    public function verify(TransactionGateway $gateway): string|array
    {
        $response = $this->statusQuery($gateway->transaction->reference, $gateway->transaction);

        if (! $response?->successful() || $response->json('code') !== self::SUCCESS_CODE) {
            return $this->errorMessage($response);
        }

        $data = (array) $response->json('data');

        return $this->verification(
            match ((string) ($data['status'] ?? '')) {
                'SUCCESS' => StatusTransaction::CONFIRMED,
                'FAIL' => StatusTransaction::FAILED,
                'CLOSE' => StatusTransaction::CANCELLED,
                default => StatusTransaction::PROCESSING,
            },
            $this->fromMinor($data['amount']['total'] ?? null),
            $data['amount']['currency'] ?? null,
            $data['orderNo'] ?? null,
            $data['instrumentType'] ?? null,
            ($data['status'] ?? null) === 'SUCCESS' ? now() : null,
            $data['failureReason'] ?? null,
            $data,
        );
    }

    public function verifyWebhook(Request $request): bool
    {
        $signature = (string) $request->input('sha512');
        $payload = (array) $request->input('payload');

        if ($signature === '' || ! $payload) {
            return false;
        }

        $signed = sprintf(
            '{Amount:"%s",Currency:"%s",Reference:"%s",Refunded:%s,Status:"%s",Timestamp:"%s",Token:"%s",TransactionID:"%s"}',
            $payload['amount'] ?? '',
            $payload['currency'] ?? '',
            $payload['reference'] ?? '',
            ($payload['refunded'] ?? false) ? 't' : 'f',
            $payload['status'] ?? '',
            $payload['timestamp'] ?? '',
            $payload['token'] ?? '',
            $payload['transactionId'] ?? '',
        );

        return hash_equals(hash_hmac('sha3-512', $signed, (string) $this->config('secret')), strtolower($signature));
    }

    public function parseWebhook(Request $request): array
    {
        $refunded = (bool) $request->input('payload.refunded');

        return [
            'event_key' => $this->bodyKey($request),
            'event_name' => trim(((string) $request->input('type')).' '.((string) $request->input('payload.status'))) ?: null,
            'topic' => $refunded ? PaymentWebhookTopicEnum::REFUND : PaymentWebhookTopicEnum::CHARGE,
            'reference' => $request->input('payload.reference'),
        ];
    }

    /**
     * OPay has no balance or whoami call, so the health check asks about a
     * reference that does not exist. Bad credentials answer 02000; anything
     * else means the keys were accepted.
     */
    public function ping(): ?string
    {
        $response = $this->statusQuery('health-check-'.now()->getTimestamp());

        if (! $response) {
            return 'OPay could not be reached.';
        }

        return $response->json('code') === self::AUTH_FAILED_CODE ? $this->errorMessage($response) : null;
    }

    public function refund(TransactionGateway $original, Transaction $refund, float $amount, ?string $reason = null): string|array
    {
        $response = $this->signedPost('/api/v1/international/payment/refund/create', array_filter([
            'amount' => [
                'currency' => $original->currency ?: $this->currency(),
                'total' => $this->toMinor($amount),
            ],
            'callbackUrl' => $this->webhookUrl(),
            'country' => (string) $this->config('country', 'NG'),
            'originalReference' => $original->transaction->reference,
            'reference' => $refund->reference,
            'refundReason' => $reason ? str()->limit($reason, 200) : null,
        ]), PaymentTraceStepEnum::REFUND, $refund);

        if (! $response?->successful() || $response->json('code') !== self::SUCCESS_CODE) {
            return $this->errorMessage($response);
        }

        return $this->fromRefund((array) $response->json('data'));
    }

    public function refundStatus(TransactionGateway $refund): string|array
    {
        $response = $this->signedPost('/api/v1/international/payment/refund/query', [
            'country' => (string) $this->config('country', 'NG'),
            'reference' => $refund->transaction->reference,
        ], PaymentTraceStepEnum::REFUND, $refund->transaction);

        if (! $response?->successful() || $response->json('code') !== self::SUCCESS_CODE) {
            return $this->errorMessage($response);
        }

        return $this->fromRefund((array) $response->json('data'));
    }

    // Tools

    protected function statusQuery(string $reference, ?Transaction $transaction = null): ?Response
    {
        return $this->signedPost('/api/v1/international/cashier/status', [
            'country' => (string) $this->config('country', 'NG'),
            'reference' => $reference,
        ], PaymentTraceStepEnum::VERIFY, $transaction);
    }

    /**
     * Every call but the cashier itself is authorised with an HMAC-SHA512 of
     * the exact body, so the body is encoded once and that one string is both
     * signed and sent.
     *
     * @param  array<string, mixed>  $data
     */
    protected function signedPost(string $path, array $data, PaymentTraceStepEnum $step, ?Transaction $transaction = null): ?Response
    {
        $body = json_encode($data, JSON_UNESCAPED_SLASHES);

        return $this->send(
            $step,
            'POST',
            $path,
            transaction: $transaction,
            request: $this->http()
                ->baseUrl($this->baseUrl())
                ->withHeaders(['MerchantId' => (string) $this->config('merchant_id')])
                ->withToken(hash_hmac('sha512', $body, (string) $this->config('secret')))
                ->withBody($body, 'application/json'),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{status: StatusTransaction, refund_id: ?string, message: ?string, raw: array<string, mixed>}
     */
    protected function fromRefund(array $data): array
    {
        return $this->refundResult(
            match ((string) ($data['orderStatus'] ?? '')) {
                'SUCCESS' => StatusTransaction::CONFIRMED,
                'FAIL' => StatusTransaction::FAILED,
                default => StatusTransaction::PROCESSING,
            },
            $data['orderNo'] ?? null,
            $data['errorMsg'] ?? $data['orderStatus'] ?? null,
            $data,
        );
    }
}
