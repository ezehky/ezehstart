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
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Monnify, by Moniepoint.
 *
 * Calls carry a bearer token from /auth/login, cached until shortly before it
 * expires. Amounts go out in major units. Our reference is Monnify's
 * paymentReference. Webhooks are signed with an HMAC-SHA512 of the raw body,
 * keyed with the secret key, in a monnify-signature header.
 */
#[Singleton]
class MonnifyGatewayService extends BasePaymentGateway
{
    public function vendor(): PaymentVendorEnum
    {
        return PaymentVendorEnum::MONNIFY;
    }

    protected function requiredKeys(): array
    {
        return ['api_key', 'secret', 'contract_code'];
    }

    protected function baseUrl(): string
    {
        return $this->isSandbox() ? 'https://sandbox.monnify.com' : 'https://api.monnify.com';
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
            return 'Monnify could not be reached. Please try again.';
        }

        $response = $this->send(PaymentTraceStepEnum::INITIALIZE, 'POST', '/api/v1/merchant/transactions/init-transaction', [
            'amount' => round((float) $transaction->amount, 2),
            'customerName' => $transaction->user->name,
            'customerEmail' => $transaction->user->email,
            'paymentReference' => $transaction->reference,
            'paymentDescription' => str()->limit($transaction->description, 200),
            'currencyCode' => $this->currency(),
            'contractCode' => (string) $this->config('contract_code'),
            'redirectUrl' => $this->callbackUrl($transaction),
            'paymentMethods' => ['CARD', 'ACCOUNT_TRANSFER', 'USSD'],
        ], $transaction);

        if (! $response?->successful() || ! $response->json('requestSuccessful')) {
            return $this->errorMessage($response);
        }

        return [
            'url' => (string) $response->json('responseBody.checkoutUrl'),
            'gateway_id' => $response->json('responseBody.transactionReference'),
        ];
    }

    public function verify(TransactionGateway $gateway): string|array
    {
        if (! $this->accessToken()) {
            return 'Monnify could not be reached. Please try again.';
        }

        $response = $this->send(PaymentTraceStepEnum::VERIFY, 'GET', '/api/v2/merchant/transactions/query', [
            'paymentReference' => $gateway->transaction->reference,
        ], $gateway->transaction);

        if (! $response?->successful() || ! $response->json('requestSuccessful')) {
            return $this->errorMessage($response);
        }

        $data = (array) $response->json('responseBody');

        return $this->verification(
            // Partly paid still comes back as confirmed, with the amount that did
            // arrive — PaymentService sees it is short and holds it for review
            // rather than crediting the full sum.
            match ((string) ($data['paymentStatus'] ?? '')) {
                'PAID', 'OVERPAID', 'PARTIALLY_PAID' => StatusTransaction::CONFIRMED,
                'FAILED', 'REVERSED' => StatusTransaction::FAILED,
                'CANCELLED', 'EXPIRED' => StatusTransaction::CANCELLED,
                default => StatusTransaction::PROCESSING,
            },
            isset($data['amountPaid']) ? (float) $data['amountPaid'] : null,
            $data['currency'] ?? $data['currencyCode'] ?? null,
            $data['transactionReference'] ?? null,
            $data['paymentMethod'] ?? null,
            $this->parseDate($data['paidOn'] ?? null),
            $data['paymentStatus'] ?? null,
            $data,
        );
    }

    public function verifyWebhook(Request $request): bool
    {
        $signature = (string) $request->header('monnify-signature');

        return $signature !== ''
            && hash_equals(hash_hmac('sha512', $request->getContent(), (string) $this->config('secret')), $signature);
    }

    public function parseWebhook(Request $request): array
    {
        $type = (string) $request->input('eventType');

        return [
            'event_key' => $this->bodyKey($request),
            'event_name' => $type ?: null,
            'topic' => match (true) {
                str_contains($type, 'REFUND') => PaymentWebhookTopicEnum::REFUND,
                str_contains($type, 'TRANSACTION') => PaymentWebhookTopicEnum::CHARGE,
                default => PaymentWebhookTopicEnum::OTHER,
            },
            // A refund event names our refund reference, which finds the refund
            // row itself; a charge event names the payment.
            'reference' => $request->input('eventData.refundReference')
                ?? $request->input('eventData.paymentReference')
                ?? $request->input('eventData.transactionReference'),
        ];
    }

    public function ping(): ?string
    {
        Cache::forget($this->tokenCacheKey());

        return $this->accessToken() ? null : 'Monnify refused the API key and secret.';
    }

    public function refund(TransactionGateway $original, Transaction $refund, float $amount, ?string $reason = null): string|array
    {
        $transactionReference = $this->paymentId($original) ?? $original->gateway_id;

        if (! $transactionReference) {
            return 'Monnify never returned a transaction reference for this payment.';
        }

        if (! $this->accessToken()) {
            return 'Monnify could not be reached. Please try again.';
        }

        $response = $this->send(PaymentTraceStepEnum::REFUND, 'POST', '/api/v1/refunds/initiate-refund', [
            'transactionReference' => $transactionReference,
            'refundReference' => $refund->reference,
            'refundAmount' => round($amount, 2),
            'refundReason' => str()->limit($reason ?: 'Refund', 60),
            'customerNote' => str()->limit($reason ?: 'Refund', 16),
        ], $refund);

        if (! $response?->successful() || ! $response->json('requestSuccessful')) {
            return $this->errorMessage($response);
        }

        return $this->fromRefund((array) $response->json('responseBody'), $refund->reference);
    }

    public function refundStatus(TransactionGateway $refund): string|array
    {
        if (! $this->accessToken()) {
            return 'Monnify could not be reached. Please try again.';
        }

        $reference = $refund->transaction->reference;

        $response = $this->send(PaymentTraceStepEnum::REFUND, 'GET', '/api/v1/refunds/'.rawurlencode($reference), transaction: $refund->transaction);

        if (! $response?->successful() || ! $response->json('requestSuccessful')) {
            return $this->errorMessage($response);
        }

        return $this->fromRefund((array) $response->json('responseBody'), $reference);
    }

    // Tools

    /**
     * Monnify's refunds are asked about by our refund reference, so that is
     * the id kept.
     *
     * @param  array<string, mixed>  $data
     * @return array{status: StatusTransaction, refund_id: ?string, message: ?string, raw: array<string, mixed>}
     */
    protected function fromRefund(array $data, string $reference): array
    {
        return $this->refundResult(
            match ((string) ($data['refundStatus'] ?? '')) {
                'COMPLETED' => StatusTransaction::CONFIRMED,
                'FAILED' => StatusTransaction::FAILED,
                default => StatusTransaction::PROCESSING,
            },
            $data['refundReference'] ?? $reference,
            $data['refundStatus'] ?? null,
            $data,
        );
    }

    protected function accessToken(): ?string
    {
        $key = $this->tokenCacheKey();

        if ($token = Cache::get($key)) {
            return $token;
        }

        $response = $this->send(
            PaymentTraceStepEnum::AUTHENTICATE,
            'POST',
            '/api/v1/auth/login',
            request: $this->http()
                ->baseUrl($this->baseUrl())
                ->withBasicAuth((string) $this->config('api_key'), (string) $this->config('secret')),
        );

        $token = $response?->successful() ? $response->json('responseBody.accessToken') : null;

        if (! $token) {
            return null;
        }

        Cache::put($key, $token, now()->addSeconds(max(60, (int) $response->json('responseBody.expiresIn', 3600) - 60)));

        return $token;
    }

    protected function tokenCacheKey(): string
    {
        return 'payments:monnify:token:'.md5((string) $this->config('api_key').($this->isSandbox() ? 'sandbox' : 'live'));
    }
}
