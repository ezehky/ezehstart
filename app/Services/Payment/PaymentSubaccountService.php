<?php

namespace App\Services\Payment;

use App\Contracts\SupportsSubaccounts;
use App\Enums\PaymentVendorEnum;
use App\Models\PaymentSubaccount;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\Cache;

/**
 * Subaccounts — the payees a checkout can be split with.
 *
 * The vendor holds the subaccount and pays its share out; a PaymentSubaccount
 * row is our copy of what the vendor answered. A row is only ever saved after
 * the vendor has accepted it, so a code on file is a code the vendor knows.
 *
 * A checkout is split by passing the row to PaymentService::checkout(), which
 * asks usableWith() first: only the subaccount's own vendor, and only a vendor
 * that can split at all, may take the payment.
 */
#[Singleton]
class PaymentSubaccountService
{
    /**
     * The vendor fields an update has to be sent on. Anything else on the
     * row is ours alone.
     */
    protected const VENDOR_FIELDS = [
        'business_name',
        'description',
        'bank_code',
        'account_number',
        'contact_email',
        'contact_phone',
        'platform_percentage',
        'status',
    ];

    // Getters

    /**
     * The banks a subaccount on this vendor can be settled to. Kept for a day
     * once the vendor has answered — the list barely moves, and the form asks
     * for it every time the vendor is changed. A failure is never kept.
     *
     * @return string|array<int, array{code: string, name: string}>
     */
    public function banks(PaymentVendorEnum $vendor): string|array
    {
        $driver = $vendor->gateway();

        if (! $driver instanceof SupportsSubaccounts) {
            return "{$vendor->label()} has no subaccounts.";
        }

        if (! $vendor->isConfigured()) {
            return "{$vendor->label()} is not configured.";
        }

        $key = "payments:subaccount-banks:{$vendor->value}:".strtoupper(kDefaultCurrency()['code']);

        if (\is_array($cached = Cache::get($key))) {
            return $cached;
        }

        $banks = $driver->banks();

        if (\is_string($banks)) {
            return $banks;
        }

        $banks = collect($banks)->unique('code')->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values()->all();

        Cache::put($key, $banks, now()->addDay());

        return $banks;
    }

    /**
     * Why a checkout through this vendor cannot be split with this
     * subaccount, or null when it can.
     */
    public function usableWith(PaymentSubaccount $subaccount, PaymentVendorEnum $vendor): ?string
    {
        if (! $vendor->supportsSubaccounts()) {
            return "{$vendor->label()} cannot split a payment with a subaccount.";
        }

        if ($subaccount->vendor !== $vendor) {
            return "This subaccount is on {$subaccount->vendor->label()}, so the payment has to go through {$subaccount->vendor->label()}.";
        }

        if (! $subaccount->exists || blank($subaccount->subaccount_code)) {
            return "This subaccount was never created on {$vendor->label()}.";
        }

        if (! $subaccount->status->isActive()) {
            return 'This subaccount is switched off.';
        }

        // The vendor settles the share in the subaccount's own currency, and the
        // ledger charges in its default. Splitting across the two would be the
        // vendor's guess at a conversion.
        if (strtoupper((string) $subaccount->currency) !== strtoupper(kDefaultCurrency()['code'])) {
            return "This subaccount takes {$subaccount->currency}, and payments here are charged in ".strtoupper(kDefaultCurrency()['code']).'.';
        }

        return null;
    }

    /**
     * The vendors a screen paying one payee can offer: available right now,
     * able to split, and holding an active subaccount from those given. A
     * payee with a Paystack and a Monnify subaccount is offered both.
     *
     * @param  iterable<int, PaymentSubaccount>  $subaccounts
     * @return array<int, PaymentVendorEnum>
     */
    public function checkoutOptionsFor(iterable $subaccounts): array
    {
        $vendors = collect($subaccounts)
            ->filter(fn (PaymentSubaccount $subaccount) => $subaccount->status->isActive())
            ->map(fn (PaymentSubaccount $subaccount) => $subaccount->vendor)
            ->all();

        return array_values(array_filter(
            PaymentVendorEnum::subaccountOptions(),
            fn (PaymentVendorEnum $vendor) => \in_array($vendor, $vendors, true),
        ));
    }

    /**
     * Whether a checkout was ever split with it. One that was stays on file:
     * the payments it took point at it.
     */
    public function isUsed(PaymentSubaccount $subaccount): bool
    {
        return $subaccount->exists && $subaccount->gateways()->exists();
    }

    // Actions

    /**
     * Create the subaccount on its vendor, or send it the changes, and save
     * the row only once the vendor has accepted them.
     *
     * Returns the saved row, or why the vendor refused. An edit that touches
     * none of the vendor's fields is saved without a call.
     */
    public function save(PaymentSubaccount $subaccount): string|PaymentSubaccount
    {
        $vendor = $subaccount->vendor;
        $driver = $vendor?->gateway();

        if (! $driver instanceof SupportsSubaccounts) {
            return ($vendor?->label() ?? 'That provider').' has no subaccounts.';
        }

        if (! $vendor->isConfigured()) {
            return "{$vendor->label()} is not configured.";
        }

        if (! $subaccount->exists) {
            $subaccount->currency = strtoupper(kDefaultCurrency()['code']);
        }

        if ($subaccount->exists && ! $subaccount->isDirty(self::VENDOR_FIELDS)) {
            $subaccount->save();

            return $subaccount;
        }

        // A different bank account is somebody the vendor has to name again;
        // the old account holder's name would be a wrong answer kept on file.
        if ($subaccount->isDirty(['bank_code', 'account_number'])) {
            $subaccount->account_name = null;
        }

        $result = $subaccount->exists ? $driver->updateSubaccount($subaccount) : $driver->createSubaccount($subaccount);

        if (\is_string($result)) {
            return $result;
        }

        $subaccount->subaccount_code = $result['subaccount_code'];
        $subaccount->vendor_record_id = $result['vendor_record_id'] ?? $subaccount->vendor_record_id;
        $subaccount->account_name = $result['account_name'] ?? $subaccount->account_name;
        $subaccount->bank_name = $result['bank_name'] ?? $this->bankName($vendor, $subaccount->bank_code) ?? $subaccount->bank_name;
        $subaccount->save();

        return $subaccount;
    }

    // Tools

    /**
     * The bank's name from the vendor's own list, for a vendor that answers a
     * create with the code alone.
     */
    protected function bankName(PaymentVendorEnum $vendor, ?string $code): ?string
    {
        $banks = $this->banks($vendor);

        return \is_array($banks) ? (collect($banks)->firstWhere('code', $code)['name'] ?? null) : null;
    }
}
