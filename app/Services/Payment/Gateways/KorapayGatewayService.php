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

/**
 * Korapay checkout.
 *
 * Amounts go out in major units; our reference is Korapay's reference.
 * Webhooks are signed differently from everybody else: an HMAC-SHA256 of only
 * the `data` object, not the body. Re-encoding that object in PHP does not
 * reliably give back the bytes Korapay signed — slashes, float formatting — so
 * the object is cut out of the raw body as it arrived, and re-encoding is only
 * the fallback.
 */
#[Singleton]
class KorapayGatewayService extends BasePaymentGateway
{
    protected const BASE_URL = 'https://api.korapay.com/merchant/api/v1';

    public function vendor(): PaymentVendorEnum
    {
        return PaymentVendorEnum::KORAPAY;
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
        $response = $this->send(PaymentTraceStepEnum::INITIALIZE, 'POST', '/charges/initialize', [
            'reference' => $transaction->reference,
            'amount' => round((float) $transaction->amount, 2),
            'currency' => $this->currency(),
            'redirect_url' => $this->callbackUrl($transaction),
            'notification_url' => $this->webhookUrl(),
            'narration' => str()->limit($transaction->description, 200),
            'customer' => [
                'name' => $transaction->user->name,
                'email' => $transaction->user->email,
            ],
        ], $transaction);

        if (! $response?->successful() || ! $response->json('status')) {
            return $this->errorMessage($response);
        }

        return [
            'url' => (string) $response->json('data.checkout_url'),
            'gateway_id' => $response->json('data.reference'),
        ];
    }

    public function verify(TransactionGateway $gateway): string|array
    {
        $response = $this->send(PaymentTraceStepEnum::VERIFY, 'GET', '/charges/'.rawurlencode($gateway->transaction->reference), transaction: $gateway->transaction);

        if (! $response?->successful() || ! $response->json('status')) {
            return $this->errorMessage($response);
        }

        $data = (array) $response->json('data');

        return $this->verification(
            match ((string) ($data['status'] ?? '')) {
                'success' => StatusTransaction::CONFIRMED,
                'failed' => StatusTransaction::FAILED,
                'expired' => StatusTransaction::CANCELLED,
                default => StatusTransaction::PROCESSING,
            },
            isset($data['amount']) ? (float) $data['amount'] : null,
            $data['currency'] ?? null,
            $data['payment_reference'] ?? $data['reference'] ?? null,
            $data['payment_method'] ?? null,
            ($data['status'] ?? null) === 'success' ? $this->parseDate($data['transaction_date'] ?? null) ?? now() : null,
            $data['message'] ?? null,
            $data,
        );
    }

    public function verifyWebhook(Request $request): bool
    {
        $signature = (string) $request->header('x-korapay-signature');
        $data = $request->input('data');

        if ($signature === '' || ! \is_array($data)) {
            return false;
        }

        $secret = (string) $this->config('secret');

        $candidates = array_filter([
            $this->rawMember($request->getContent(), 'data'),
            json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            json_encode($data),
        ]);

        foreach ($candidates as $candidate) {
            if (hash_equals(hash_hmac('sha256', $candidate, $secret), $signature)) {
                return true;
            }
        }

        return false;
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
                default => PaymentWebhookTopicEnum::OTHER,
            },
            // A refund event's reference is the refund's own; the payment it
            // belongs to is payment_reference.
            'reference' => str_starts_with($event, 'refund.')
                ? ($request->input('data.payment_reference') ?? $request->input('data.reference'))
                : $request->input('data.reference'),
        ];
    }

    public function ping(): ?string
    {
        $response = $this->send(PaymentTraceStepEnum::HEALTH, 'GET', '/balances');

        return $response?->successful() ? null : $this->errorMessage($response);
    }

    public function refund(TransactionGateway $original, Transaction $refund, float $amount, ?string $reason = null): string|array
    {
        $response = $this->send(PaymentTraceStepEnum::REFUND, 'POST', '/refunds/initiate', array_filter([
            'payment_reference' => $original->transaction->reference,
            'reference' => $refund->reference,
            'amount' => round($amount, 2),
            'reason' => $reason ? str()->limit($reason, 195) : null,
            'webhook_url' => $this->webhookUrl(),
        ]), $refund);

        if (! $response?->successful() || ! $response->json('status')) {
            return $this->errorMessage($response);
        }

        return $this->fromRefund((array) $response->json('data'), $refund->reference);
    }

    public function refundStatus(TransactionGateway $refund): string|array
    {
        $reference = $refund->transaction->reference;

        $response = $this->send(PaymentTraceStepEnum::REFUND, 'GET', '/refunds/'.rawurlencode($reference), transaction: $refund->transaction);

        if (! $response?->successful() || ! $response->json('status')) {
            return $this->errorMessage($response);
        }

        return $this->fromRefund((array) $response->json('data'), $reference);
    }

    // Tools

    /**
     * The exact bytes of a top-level object member in a JSON document, by
     * walking the braces and skipping over strings. Null when the member is not
     * a top-level object.
     */
    /**
     * @param  array<string, mixed>  $data
     * @return array{status: StatusTransaction, refund_id: ?string, message: ?string, raw: array<string, mixed>}
     */
    protected function fromRefund(array $data, string $reference): array
    {
        return $this->refundResult(
            match ((string) ($data['status'] ?? '')) {
                'success' => StatusTransaction::CONFIRMED,
                'failed' => StatusTransaction::FAILED,
                default => StatusTransaction::PROCESSING,
            },
            $data['refund_reference'] ?? $data['reference'] ?? $reference,
            $data['status'] ?? null,
            $data,
        );
    }

    protected function rawMember(string $json, string $member): ?string
    {
        if (! preg_match('/^\s*\{.*?"'.preg_quote($member, '/').'"\s*:\s*\{/s', $json, $match)) {
            return null;
        }

        $start = \strlen($match[0]) - 1;
        $depth = 0;
        $inString = false;

        for ($index = $start, $length = \strlen($json); $index < $length; $index++) {
            $character = $json[$index];

            if ($inString) {
                if ($character === '\\') {
                    $index++;
                } elseif ($character === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($character === '"') {
                $inString = true;
            } elseif ($character === '{') {
                $depth++;
            } elseif ($character === '}' && --$depth === 0) {
                return substr($json, $start, $index - $start + 1);
            }
        }

        return null;
    }
}
