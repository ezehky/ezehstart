<?php

namespace App\Http\Controllers;

use App\Enums\PaymentVendorEnum;
use App\Services\Payment\PaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Where a vendor sends the customer back to after its checkout.
 *
 * Nothing in the URL is believed — not a status, not an amount, not the
 * vendor's own transaction id. The reference only says which row to ask about;
 * the vendor is asked, and the row settles on its answer. So the route needs no
 * signature and no session: anybody hitting it can only make the server check
 * a payment's real state sooner.
 */
class PaymentCallbackController extends Controller
{
    public function __invoke(Request $request, string $vendor, string $reference): RedirectResponse
    {
        $case = PaymentVendorEnum::tryFrom($vendor);

        abort_if(! $case || $case->isManual(), 404);

        $service = app(PaymentService::class);
        $transaction = $service->findByReference($reference);

        abort_if(! $transaction || $transaction->gateway?->vendor !== $case, 404);

        $result = $service->verify($transaction);

        // Somebody else's reference gets the same redirect as a stranger, and no
        // word about the payment it belongs to.
        if (auth()->id() !== $transaction->user_id) {
            return redirect()->route('user.transactions');
        }

        $redirect = redirect()->route('user.transactions');

        if (\is_string($result)) {
            return $redirect->with('error', "We could not confirm payment {$reference} with {$case->label()} yet. It will update on its own once they confirm it.");
        }

        if ($result->status->isConfirmed()) {
            return $redirect->with('status', "Your payment {$reference} ".$result->status->endMessage());
        }

        if ($result->status->isFailed() || $result->status->isCancelled()) {
            return $redirect->with('error', "Your payment {$reference} ".$result->status->endMessage());
        }

        if ($result->status->isConfirmedButNotLogged() || $result->gateway?->failure_reason) {
            return $redirect->with('status', "Your payment {$reference} has been received and is being reviewed. We will let you know once it is settled.");
        }

        return $redirect->with(
            $request->boolean('cancelled') ? 'error' : 'status',
            $request->boolean('cancelled')
                ? "You left the checkout before paying. Payment {$reference} has not been taken."
                : "Your payment {$reference} ".$result->status->endMessage(),
        );
    }
}
