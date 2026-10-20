<?php

namespace App\Http\Controllers;

use App\Enums\LogChannelEnum;
use App\Enums\PaymentTraceStepEnum;
use App\Enums\PaymentVendorEnum;
use App\Services\Payment\PaymentService;
use App\Services\Payment\PaymentTraceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Where vendors post their webhooks.
 *
 * The order is the security model: fence, signature, then storage, and only
 * then work. Nothing reads the body before the signature has been checked,
 * and the work itself happens on the queue, so the vendor gets its 200 in
 * milliseconds and has no reason to retry.
 */
class PaymentWebhookController extends Controller
{
    public function __invoke(Request $request, string $vendor): JsonResponse
    {
        $case = PaymentVendorEnum::tryFrom($vendor);

        // An unknown or unconfigured vendor is a 404, not a 401: there is
        // nothing at this address for anybody to authenticate against.
        abort_if(! $case || $case->isManual() || ! $case->isConfigured(), 404);

        $allowed = (array) config('payments.webhooks.allowed_ips', []);

        if ($allowed && ! \in_array($request->ip(), $allowed, true)) {
            return $this->refuse($case, $request, 403, 'Address not allowed.');
        }

        if ($this->mustVerify() && ! $case->gateway()->verifyWebhook($request)) {
            return $this->refuse($case, $request, 401, 'Invalid signature.');
        }

        if (! \is_array($request->json()->all()) || ! $request->json()->all()) {
            return response()->json(['status' => 'empty'], 400);
        }

        $webhook = app(PaymentService::class)->receiveWebhook($case, $request);

        return response()->json(['status' => $webhook ? 'queued' : 'duplicate']);
    }

    /**
     * Signature checks can be switched off for a sandbox that sends unsigned
     * calls — in local and testing only. Anywhere else the switch is ignored,
     * and the fact that somebody tried is logged.
     */
    protected function mustVerify(): bool
    {
        if (config('payments.webhooks.verify_signature', true)) {
            return true;
        }

        if (app()->environment('local', 'testing')) {
            return false;
        }

        Log::channel(LogChannelEnum::PAYMENTS->value)->error('PAYMENTS_WEBHOOK_VERIFY_SIGNATURE is off outside local/testing; it has been ignored and signatures are still checked.');

        return true;
    }

    protected function refuse(PaymentVendorEnum $vendor, Request $request, int $status, string $message): JsonResponse
    {
        app(PaymentTraceService::class)->record(
            $vendor,
            PaymentTraceStepEnum::WEBHOOK,
            false,
            method: 'POST',
            endpoint: $request->path(),
            httpStatus: $status,
            message: "{$message} From {$request->ip()}.",
        );

        return response()->json(['status' => 'refused'], $status);
    }
}
