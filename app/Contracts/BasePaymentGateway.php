<?php

namespace App\Contracts;

use App\Enums\PaymentTraceStepEnum;
use App\Enums\PaymentVendorEnum;
use App\Enums\StatusSubscription;
use App\Enums\StatusTransaction;
use App\Enums\SubscriptionEventEnum;
use App\Models\Transaction;
use App\Models\TransactionGateway;
use App\Services\Payment\PaymentTraceService;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The plumbing every gateway service shares: reading its keys, building an HTTP
 * client, sending and tracing a call, and the shapes the contract returns.
 *
 * A vendor service fills in the vendor-specific parts and nothing else. If two
 * vendors need the same helper, it belongs here.
 */
abstract class BasePaymentGateway implements PaymentGateway
{
    abstract public function vendor(): PaymentVendorEnum;

    /**
     * The config keys under payments.vendors.{vendor} that must be filled in
     * before the vendor is offered.
     *
     * @return array<int, string>
     */
    abstract protected function requiredKeys(): array;

    /**
     * The client every call to this vendor starts from — base URL and
     * credentials. A vendor that authenticates per call overrides it.
     */
    abstract protected function client(): PendingRequest;

    public function isConfigured(): bool
    {
        foreach ($this->requiredKeys() as $key) {
            if (blank($this->config($key))) {
                return false;
            }
        }

        return true;
    }

    // Tools

    /**
     * The shape refund() and refundStatus() return.
     *
     * @param  array<string, mixed>  $raw
     * @return array{status: StatusTransaction, refund_id: ?string, message: ?string, raw: array<string, mixed>}
     */
    protected function refundResult(StatusTransaction $status, mixed $refundId = null, ?string $message = null, array $raw = []): array
    {
        return [
            'status' => $status,
            'refund_id' => $refundId !== null && $refundId !== '' ? (string) $refundId : null,
            'message' => $message,
            'raw' => $raw,
        ];
    }

    /**
     * The shape fetchSubscription() returns.
     *
     * @return array{status: StatusSubscription, vendor_subscription_id: ?string, period_ends_at: ?CarbonInterface, cancel_at_period_end: bool, token: ?string}
     */
    protected function subscriptionState(
        StatusSubscription $status,
        mixed $vendorSubscriptionId = null,
        ?CarbonInterface $periodEndsAt = null,
        bool $cancelAtPeriodEnd = false,
        ?string $token = null,
    ): array {
        return [
            'status' => $status,
            'vendor_subscription_id' => filled($vendorSubscriptionId) ? (string) $vendorSubscriptionId : null,
            'period_ends_at' => $periodEndsAt,
            'cancel_at_period_end' => $cancelAtPeriodEnd,
            'token' => $token,
        ];
    }

    /**
     * The shape parseSubscriptionEvent() returns.
     *
     * @return array{event: SubscriptionEventEnum, vendor_subscription_id: ?string, customer_email: ?string, vendor_plan_id: ?string, payment_id: ?string, period_ends_at: ?CarbonInterface, token: ?string}
     */
    protected function subscriptionEvent(
        SubscriptionEventEnum $event,
        mixed $vendorSubscriptionId = null,
        ?string $customerEmail = null,
        mixed $vendorPlanId = null,
        mixed $paymentId = null,
        ?CarbonInterface $periodEndsAt = null,
        ?string $token = null,
    ): array {
        return [
            'event' => $event,
            'vendor_subscription_id' => filled($vendorSubscriptionId) ? (string) $vendorSubscriptionId : null,
            'customer_email' => $customerEmail ? strtolower($customerEmail) : null,
            'vendor_plan_id' => filled($vendorPlanId) ? (string) $vendorPlanId : null,
            'payment_id' => filled($paymentId) ? (string) $paymentId : null,
            'period_ends_at' => $periodEndsAt,
            'token' => $token,
        ];
    }

    /**
     * The id the money moved under, which most vendors refund against. Missing
     * when the payment was never verified through the kit.
     */
    protected function paymentId(TransactionGateway $original): ?string
    {
        return $original->gateway_request_id ?: null;
    }

    protected function config(string $key, mixed $default = null): mixed
    {
        return config("payments.vendors.{$this->vendor()->value}.{$key}", $default);
    }

    protected function isSandbox(): bool
    {
        return (bool) $this->config('sandbox', true);
    }

    /**
     * A bare client: JSON, a timeout, and a retry on connection failures only.
     * A 4xx is an answer, and retrying it would only ask the same wrong question
     * again — or, for a charge, risk asking twice.
     */
    protected function http(): PendingRequest
    {
        return Http::acceptJson()
            ->timeout(config('payments.http.timeout', 30))
            ->retry(
                max(1, (int) config('payments.http.retries', 2)),
                250,
                fn (Throwable $exception) => $exception instanceof ConnectionException,
                throw: false,
            );
    }

    /**
     * Send one call and trace it. Null when the vendor could not be reached at
     * all; otherwise the response, successful or not, for the caller to read.
     *
     * @param  array<string, mixed>  $data
     */
    protected function send(
        PaymentTraceStepEnum $step,
        string $method,
        string $url,
        array $data = [],
        ?Transaction $transaction = null,
        ?PendingRequest $request = null,
    ): ?Response {
        $started = hrtime(true);
        $request ??= $this->client();

        try {
            // get() puts the data in the query string; post() and the rest send it
            // in whatever body format the client was built with — JSON, or the
            // form encoding Stripe asks for.
            $response = $request->{strtolower($method)}($url, $data);
        } catch (ConnectionException $exception) {
            $this->trace($step, false, $method, $url, null, $started, $exception->getMessage(), $data, [], $transaction);

            return null;
        }

        $this->trace(
            $step,
            $response->successful(),
            $method,
            $url,
            $response->status(),
            $started,
            $response->successful() ? null : $this->errorMessage($response),
            $data,
            (array) $response->json(),
            $transaction,
        );

        return $response;
    }

    /**
     * @param  array<string, mixed>  $request
     * @param  array<string, mixed>  $response
     */
    protected function trace(
        PaymentTraceStepEnum $step,
        bool $succeeded,
        ?string $method,
        ?string $url,
        ?int $status,
        int|float $started,
        ?string $message,
        array $request = [],
        array $response = [],
        ?Transaction $transaction = null,
    ): void {
        app(PaymentTraceService::class)->record(
            $this->vendor(),
            $step,
            $succeeded,
            method: $method,
            endpoint: $url,
            httpStatus: $status,
            durationMs: (int) ((hrtime(true) - $started) / 1_000_000),
            message: $message,
            context: ['request' => $request, 'response' => $response],
            transaction: $transaction,
        );
    }

    /**
     * The vendor's own explanation, where it gave one in a shape we recognise,
     * so the toast says "Invalid key" rather than "HTTP 401".
     */
    protected function errorMessage(?Response $response, string $fallback = 'The payment provider could not be reached. Please try again.'): string
    {
        if (! $response) {
            return $fallback;
        }

        $message = $response->json('message')
            ?? $response->json('error.message')
            ?? $response->json('error_description')
            ?? $response->json('responseMessage')
            ?? $response->json('details.0.description');

        return \is_string($message) && $message !== '' ? $message : $fallback;
    }

    /**
     * Where the vendor sends the customer back to. The reference is in the path
     * rather than the query string, because some vendors append their own
     * query string to the URL without checking whether it already has one.
     */
    protected function callbackUrl(Transaction $transaction, bool $cancelled = false): string
    {
        return route('payments.callback', [
            'vendor' => $this->vendor()->value,
            'reference' => $transaction->reference,
            ...($cancelled ? ['cancelled' => 1] : []),
        ]);
    }

    protected function webhookUrl(): string
    {
        return route('payments.webhook', ['vendor' => $this->vendor()->value]);
    }

    /**
     * The currency a checkout is opened in — the ledger's own.
     */
    protected function currency(): string
    {
        return strtoupper(kDefaultCurrency()['code']);
    }

    protected function toMinor(float $amount): int
    {
        return (int) round($amount * 100);
    }

    protected function fromMinor(int|float|string|null $amount): ?float
    {
        return $amount === null ? null : round((float) $amount / 100, 2);
    }

    protected function expiresInMinutes(): int
    {
        return max(5, (int) config('payments.checkout.expires_in_minutes', 60));
    }

    /**
     * The key a webhook is deduplicated by when the vendor sends no event id of
     * its own: the body, hashed. A retry is byte-for-byte the same delivery.
     */
    protected function bodyKey(Request $request): string
    {
        return hash('sha256', $request->getContent());
    }

    protected function parseDate(mixed $value): ?CarbonInterface
    {
        if (blank($value)) {
            return null;
        }

        try {
            return is_numeric($value) ? Carbon::createFromTimestamp((int) $value) : Carbon::parse((string) $value);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The shape verify() returns, built in one place so no vendor forgets a key.
     *
     * @param  array<string, mixed>  $raw
     * @return array{status: StatusTransaction, amount: ?float, currency: ?string, payment_id: ?string, channel: ?string, paid_at: ?CarbonInterface, message: ?string, raw: array<string, mixed>}
     */
    protected function verification(
        StatusTransaction $status,
        ?float $amount = null,
        ?string $currency = null,
        mixed $paymentId = null,
        ?string $channel = null,
        ?CarbonInterface $paidAt = null,
        ?string $message = null,
        array $raw = [],
    ): array {
        return [
            'status' => $status,
            'amount' => $amount,
            'currency' => $currency ? strtoupper($currency) : null,
            'payment_id' => $paymentId !== null ? (string) $paymentId : null,
            'channel' => $channel,
            'paid_at' => $paidAt,
            'message' => $message,
            'raw' => $raw,
        ];
    }
}
