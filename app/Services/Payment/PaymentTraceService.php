<?php

namespace App\Services\Payment;

use App\Enums\LogChannelEnum;
use App\Enums\PaymentTraceStepEnum;
use App\Enums\PaymentVendorEnum;
use App\Models\PaymentTrace;
use App\Models\Transaction;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A record of every call made to a vendor, for diagnosing an integration.
 *
 * Off unless payments.tracing.enabled is on. Whatever is written is redacted
 * first — a trace table full of card numbers and API keys would be the most
 * valuable table in the database to somebody who should not have it.
 */
#[Singleton]
class PaymentTraceService
{
    /**
     * Keys whose value is replaced wherever they appear, at any depth. Matched
     * whole and case-insensitively.
     */
    protected const REDACTED_KEYS = [
        'authorization', 'access_token', 'accesstoken', 'refresh_token', 'client_secret',
        'card', 'card_number', 'cardnumber', 'pan', 'cvv', 'cvc', 'pin', 'expiry',
        'exp_month', 'exp_year', 'expiry_month', 'expiry_year', 'otp', 'bvn',
        'account_number', 'accountnumber', 'token', 'api_key', 'apikey', 'sha512',
    ];

    /**
     * Fragments that redact any key containing them — secret_key, webhook_secret,
     * password_confirmation.
     */
    protected const REDACTED_FRAGMENTS = ['secret', 'password'];

    // Getters

    public function isEnabled(): bool
    {
        return (bool) config('payments.tracing.enabled', false);
    }

    public function retentionDays(): int
    {
        return max(1, (int) config('payments.tracing.retention_days', 30));
    }

    // Actions

    /**
     * Write a trace row. Never throws: a trace that fails to write must not be
     * the reason a payment does.
     *
     * @param  array<string, mixed>  $context
     */
    public function record(
        PaymentVendorEnum $vendor,
        PaymentTraceStepEnum $step,
        bool $succeeded,
        ?string $method = null,
        ?string $endpoint = null,
        ?int $httpStatus = null,
        ?int $durationMs = null,
        ?string $message = null,
        array $context = [],
        ?Transaction $transaction = null,
    ): ?PaymentTrace {
        if (! $this->isEnabled()) {
            return null;
        }

        try {
            return PaymentTrace::query()->create([
                'transaction_id' => $transaction?->id,
                'vendor' => $vendor,
                'trace_step' => $step,
                'method' => $method ? strtoupper($method) : null,
                'endpoint' => $endpoint ? str()->limit($endpoint, 495) : null,
                'http_status' => $httpStatus,
                'duration_ms' => $durationMs,
                'succeeded' => $succeeded,
                'message' => $message ? str()->limit($message, 495) : null,
                'context' => $this->redact($context),
            ]);
        } catch (Throwable $exception) {
            Log::channel(LogChannelEnum::PAYMENTS->value)->warning('Payment trace could not be written.', ['error' => $exception->getMessage()]);

            return null;
        }
    }

    /**
     * Replace every sensitive value, at any depth.
     *
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    public function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (\is_string($key) && $this->isSensitive($key)) {
                $data[$key] = '[redacted]';

                continue;
            }

            if (\is_array($value)) {
                $data[$key] = $this->redact($value);
            }
        }

        return $data;
    }

    /**
     * Delete traces older than the retention window, in chunks, and report how
     * many went. Same shape as the activity log prune, for the same reason: one
     * unbounded delete on a big table is a lock held long enough to hurt.
     */
    public function prune(?int $days = null, int $chunk = 1000): int
    {
        $cutoff = now()->subDays($days ?? $this->retentionDays());
        $deleted = 0;

        do {
            $passed = PaymentTrace::query()
                ->where('created_at', '<', $cutoff)
                ->limit($chunk)
                ->delete();

            $deleted += $passed;
        } while ($passed === $chunk);

        return $deleted;
    }

    // Tools

    protected function isSensitive(string $key): bool
    {
        $key = strtolower($key);

        if (\in_array($key, self::REDACTED_KEYS, true)) {
            return true;
        }

        foreach (self::REDACTED_FRAGMENTS as $fragment) {
            if (str_contains($key, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
