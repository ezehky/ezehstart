<?php

namespace App\Contracts;

use App\Enums\PaymentVendorEnum;
use App\Enums\PaymentWebhookTopicEnum;
use App\Enums\StatusTransaction;
use App\Models\Transaction;
use App\Models\TransactionGateway;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;

/**
 * What every vendor has to answer, in one vocabulary.
 *
 * A gateway service only talks to its vendor and translates. It never touches
 * the ledger: PaymentService decides what an answer means for a transaction,
 * so the rules about amounts, currencies and double-settling live in one place
 * rather than seven.
 *
 * Errors come back the house way — a string is the reason it failed, anything
 * else is the result.
 */
interface PaymentGateway
{
    public function vendor(): PaymentVendorEnum;

    /**
     * Every key the vendor needs is in the environment.
     */
    public function isConfigured(): bool;

    /**
     * Open a checkout for a transaction already written as PROCESSING.
     *
     * The transaction's reference is handed to the vendor as its own reference
     * wherever the API takes one, which is what lets a webhook find its way back.
     *
     * gateway_id is the vendor's id for the checkout itself — the session, the
     * order — and is what verify() asks about afterwards.
     *
     * @return string|array{url: string, gateway_id: ?string}
     */
    public function initialize(Transaction $transaction): string|array;

    /**
     * Ask the vendor what actually happened. Never trusts anything the customer's
     * browser carried back.
     *
     * payment_id is the vendor's id for the money that moved — the charge, the
     * capture, the payment intent — kept as gateway_request_id because it is
     * what a refund is issued against. Distinct from the checkout's own id,
     * which verification keeps needing.
     *
     * @return string|array{status: StatusTransaction, amount: ?float, currency: ?string, payment_id: ?string, channel: ?string, paid_at: ?CarbonInterface, message: ?string, raw: array<string, mixed>}
     */
    public function verify(TransactionGateway $gateway): string|array;

    /**
     * Whether the call really came from the vendor. Runs before anything else
     * reads the body.
     */
    public function verifyWebhook(Request $request): bool;

    /**
     * Read a verified webhook into the shared vocabulary.
     *
     * The event key is the vendor's own event id where it sends one, so retries
     * collapse onto one row; a hash of the body where it does not.
     *
     * @return array{event_key: string, event_name: ?string, topic: PaymentWebhookTopicEnum, reference: ?string}
     */
    public function parseWebhook(Request $request): array;

    /**
     * A cheap authenticated call, for the health check. Null when the vendor
     * answered and accepted the keys, otherwise what went wrong.
     */
    public function ping(): ?string;

    /**
     * Ask the vendor to send money back for a confirmed payment.
     *
     * $original is the payment's gateway row — its gateway_request_id is the id
     * the money moved under. $refund is the REFUND row already written as
     * PROCESSING; its reference is our refund reference wherever the vendor
     * takes one. The amount is in major units and may be part of the payment.
     *
     * refund_id is the vendor's own id for the refund, kept as the refund row's
     * gateway_id so refundStatus() can ask about it later.
     *
     * @return string|array{status: StatusTransaction, refund_id: ?string, message: ?string, raw: array<string, mixed>}
     */
    public function refund(TransactionGateway $original, Transaction $refund, float $amount, ?string $reason = null): string|array;

    /**
     * Ask the vendor where a refund it accepted has got to.
     *
     * @return string|array{status: StatusTransaction, refund_id: ?string, message: ?string, raw: array<string, mixed>}
     */
    public function refundStatus(TransactionGateway $refund): string|array;
}
