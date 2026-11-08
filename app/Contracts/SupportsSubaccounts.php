<?php

namespace App\Contracts;

use App\Models\PaymentSubaccount;
use App\Models\Transaction;

/**
 * A vendor that can split a checkout with a subaccount — Paystack,
 * Flutterwave, Monnify. A gateway service implements this beside
 * PaymentGateway; one that does not is never offered where a payment is
 * split, and a checkout naming a subaccount refuses it.
 *
 * The vendor holds the subaccount and pays its share out. The kit keeps a
 * PaymentSubaccount row of what the vendor answered, and names its code on
 * every checkout split with it.
 */
interface SupportsSubaccounts
{
    /**
     * The banks a subaccount can be settled to, as the vendor codes them.
     * Codes are the vendor's own — Flutterwave's are not Paystack's.
     *
     * @return string|array<int, array{code: string, name: string}>
     */
    public function banks(): string|array;

    /**
     * Create the subaccount on the vendor from an unsaved row.
     *
     * @return string|array{subaccount_code: string, vendor_record_id: ?string, account_name: ?string, bank_name: ?string}
     */
    public function createSubaccount(PaymentSubaccount $subaccount): string|array;

    /**
     * Send a row's changed details — business name, bank account, split,
     * status — to the copy already on the vendor.
     *
     * @return string|array{subaccount_code: string, vendor_record_id: ?string, account_name: ?string, bank_name: ?string}
     */
    public function updateSubaccount(PaymentSubaccount $subaccount): string|array;

    /**
     * The fields a checkout carries to be split with this subaccount, merged
     * into the vendor's initialize call.
     *
     * @return array<string, mixed>
     */
    public function splitFields(PaymentSubaccount $subaccount, Transaction $transaction): array;
}
