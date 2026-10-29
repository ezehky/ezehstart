# payments.md

## Rule

**Every gateway payment goes through `PaymentService` and is settled only by
`PaymentService::verify()`.** The ledger (`TransactionService`) is still the record;
the payments module only ever writes through it.

| Piece | Where | Job |
| --- | --- | --- |
| Contract | `app/Contracts/PaymentGateway.php` | What every vendor must answer, in one vocabulary |
| Base | `app/Contracts/BasePaymentGateway.php` | Config, HTTP client, `send()` + tracing, money units, result shapes |
| Vendors | `app/Services/Payment/Gateways/{Vendor}GatewayService.php` | Talk to one vendor and translate. Never touch the ledger |
| Orchestrator | `app/Services/Payment/PaymentService.php` | Checkout, verify, webhooks, reconcile — every decision |
| Refunds | `app/Services/Payment/RefundService.php` | Refund rows, the refundable check, refund webhooks |
| Subscriptions | `app/Services/Payment/SubscriptionService.php`, `app/Contracts/SupportsSubscriptions.php` | Plans synced to vendors, the first payment, renewals, cancel, sync |
| Tracing | `app/Services/Payment/PaymentTraceService.php` | Redacted call log, off by default |
| Vendor list | `PaymentVendorEnum` | Case → gateway service, labels, availability |
| Config | `config/payments.php` | Keys (env only), queue, reconcile windows, tracing |
| Callback | `PaymentCallbackController` | `GET /payments/{vendor}/callback/{reference}` |
| Webhook | `PaymentWebhookController` | `POST /webhooks/payments/{vendor}` |
| Queue | `app/Jobs/ProcessPaymentWebhook.php` | Works a stored webhook with retries |
| Events | `app/Events/Payment*.php`, `Refund*.php`, `Subscription*.php` | Extension points for the project |
| Admin | `pages::admin.payments` (gate `payments`), `pages::admin.subscriptions` (gate `subscriptions`), refund + "check with provider" on `pages::admin.transactions` | |
| Member | "Add funds" on `pages::user.transactions`, `pages::user.subscriptions` | |
| Commands | `payments:reconcile`, `payments:retry-webhooks`, `payments:health`, `payments:prune-traces` | |

Seven vendors ship: Paystack, Flutterwave, Stripe, PayPal, OPay, Monnify, Korapay.
All of them are plain `Http::` calls on guzzle 8 — no vendor SDK is a dependency.

## The flow

```
member picks amount + vendor
  → PaymentService::checkout()
      TransactionService::record(... PROCESSING, via PAYMENT_GATEWAY, vendor)
      gateway->initialize($transaction)    → url + checkout id
      PaymentInitialized
  → redirect to gateway_url

vendor sends the customer back            vendor posts a webhook          every 10 min
  PaymentCallbackController                 PaymentWebhookController        payments:reconcile
                                              fence → signature → store
                                              ProcessPaymentWebhook (queue)
          \_____________________________________|______________________________/
                                                 ↓
                                     PaymentService::verify()
                                       gateway->verify()  (asks the vendor)
                                       lock the row
                                       amount + currency must match
                                         ✓ settle CONFIRMED → PaymentSucceeded
                                         ✗ hold, failure_reason → PaymentNeedsReview
                                       failed / cancelled → settle → PaymentFailed
```

## The rules

1. **Nothing the browser carries back is believed.** The callback URL holds only our
   reference; the status comes from the vendor.
2. **Amount and currency must match.** Short or wrong-currency money is held:
   `transaction_gateways.failure_reason` set, status left PROCESSING, admins notified.
   Money landing on a row already closed becomes `CONFIRMED_BUT_NOT_LOGGED`. Neither
   is credited automatically; a person settles it.
3. **A row is settled once.** `verify()` locks the transaction and re-checks; a second
   answer is a no-op. Duplicate webhooks die on `unique(vendor, event_key)`.
4. **The signature is checked before the body is read.** Turning verification off is
   honoured in `local` and `testing` only.
5. **Keys live in the environment.** Never in the site configuration — an admin
   screen that can read a secret back makes it not a secret.
6. **Traces and stored payloads are redacted** through `PaymentTraceService::redact()`.

## Refunds

A refund is **its own `REFUND` row** whose `transactionable` is the payment. The
payment is never edited — it stays `CONFIRMED`, because it happened.

| Payment | Refund row |
| --- | --- |
| Deposit (`CREDIT`, `BALANCE`) | `DEBIT`, `BALANCE` — the balance falls when it confirms |
| Direct purchase (`DIRECT`, `NONE`) | `DIRECT`, `NONE` — no balance effect |
| Gateway payment | sent through the vendor; `PROCESSING` until it says otherwise |
| Approved bank transfer (`MANUAL`) | the admin pays it back by hand; recorded `CONFIRMED` at once |

- What is left to refund is the payment less every refund row that is `CONFIRMED` or
  `PROCESSING`. A `FAILED` one frees its amount again.
- The check and the `PROCESSING` row are written under a lock on the payment, so two
  admins cannot together refund more than came in.
- A deposit the member has partly spent cannot be refunded past their balance.
- `PaymentService::verify()` hands a `REFUND` row to `RefundService::verifyRefund()`, so
  the webhook, the reconcile sweep and "check with provider" all work for refunds too.
- A refund webhook with no refund row behind it — a refund made from the vendor's own
  dashboard — fires `PaymentNeedsReview`, because the balance does not know.
- Only money that came from outside is refundable: `PAYMENT_GATEWAY` or
  `BANK_TRANSFER`. Anything else is an adjustment.

## Subscriptions

Billed by the vendor — it holds the card, runs the schedule and retries. Paystack,
Flutterwave, Stripe and PayPal implement `SupportsSubscriptions`; OPay, Monnify and
Korapay do not, and are never offered for a plan.

| Piece | How |
| --- | --- |
| Plan | `subscription_plans`, admin-managed: name, `is_free`, `is_recommended`, `trial_days`, `includes_plan_id`. Prices live apart from it |
| Price | `subscription_plan_prices`, one row an interval — monthly and yearly are separate amounts, and the card shows what a longer interval saves (`savingsAgainst()`, computed, never typed in). An emptied interval is switched off, not deleted. A **price** is what is synced to a vendor on its first subscriber (`subscription_plan_vendors`, unique per price and vendor); the product it hangs off is the plan's. A changed amount empties that price's vendor plan id and keeps the product; existing subscribers keep `subscriptions.vendor_plan_id` |
| Features | `subscription_plan_features`, one row a `SubscriptionFeatureEnum` case the plan grants, `feature_limit` for a limit case (null = unlimited). A plan with `includes_plan_id` grants everything in that plan too ("Everything in Basic, plus") — the more generous limit wins, and its card lists only what it adds. The shipped cases are examples; replace them |
| Free plan | at most one; no prices; nobody subscribes to it. It is what `planFor()` falls back to when nothing else gives access — no row per account |
| Trial | the kit's own, not the vendor's: `startTrial()` writes a `TRIALING` row with no vendor, no card, no charge, ending at `current_period_ends_at`. One per account (a trial is the only vendor-less row). Paying ends it at once; otherwise the sync sweep ends it |
| Start | `SubscriptionService::subscribe()` writes a PENDING subscription and the first payment's PROCESSING row (`SUBSCRIPTION` group, `DIRECT`, `NONE` wallet, against the subscription), then the vendor checkout |
| First payment | the ordinary callback / webhook / sweep → `PaymentService::verify()`, which uses `verifySubscriptionCheckout()` for these rows and calls `activate()` on confirm |
| Renewal | a webhook the driver reads as `RENEWED` → `verifyRenewal()` asks the vendor about that charge → one `SUBSCRIPTION` row, deduplicated by the vendor's charge id across every payment on the subscription |
| Failed renewal | `PAST_DUE` + `SubscriptionPaymentFailed`. Access during it is the project's call |
| Anything else | `CHANGED` → the subscription is re-read from the vendor (`refresh()`) |
| Cancel | stops the vendor billing; the row is `CANCELLED` with access to `current_period_ends_at`, then `EXPIRED` via `payments:sync-subscriptions` (hourly) |

**Gate paid features by feature, never by plan name:**
`app(SubscriptionService::class)->allows($user, SubscriptionFeatureEnum::API_ACCESS)`
and `->limitFor($user, SubscriptionFeatureEnum::PROJECTS)` (null is unlimited, 0 is not
granted). Both read the plan `accessFor()` finds — which already counts a `CANCELLED`
row with time left and a running trial — and fall back to the free plan.

No coupons or discount codes ship. A yearly price below twelve monthlies is the
built-in discount.

Vendor notes: a renewal on Paystack is `charge.success` with a plan and a Paystack
reference; on Flutterwave, `charge.completed` whose `tx_ref` is not ours, matched by the
customer's email; on Stripe, `invoice.paid` not for `subscription_create`; on PayPal,
`PAYMENT.SALE.COMPLETED` — which PayPal also sends for the first payment, hence the
dedupe. A PayPal subscription payment is refunded through the sales API, not captures.

## Two ids per checkout

| Column | Holds | Used for |
| --- | --- | --- |
| `gateway_id` | The checkout's id — Stripe session, PayPal order, OPay order | `verify()` |
| `gateway_request_id` | The money's id — payment intent, capture, charge id | refunds |

`initialize()` returns the first; `verify()` returns the second as `payment_id`.
Overwriting `gateway_id` with a payment id breaks re-verification for Stripe and PayPal.

## Taking a payment for something

A deposit is the default. A purchase passes its own group, the DIRECT type, the NONE
wallet, and the model it pays for:

```php
$result = app(PaymentService::class)->checkout(
    $user,
    PaymentVendorEnum::from($this->vendor),
    $order->total,
    "Order {$order->number}",
    TransactionGroupEnum::PURCHASE,
    TransactionTypeEnum::DIRECT,
    TransactionWalletEnum::NONE,
    against: $order,
);

$this->respondError($result, \is_string($result));

return $this->redirect($result->gateway->gateway_url);
```

Fulfil in a listener — the transaction's `transactionable` is the order:

```php
// app/Listeners/FulfilOrders.php — discovered automatically
public function handle(PaymentSucceeded $event): void
{
    if ($event->transaction->transactionable instanceof Order) {
        app(OrderService::class)->markPaid($event->transaction->transactionable);
    }
}
```

### The processing fee

`PaymentFeeService` holds a fee per vendor — a percentage, plus a flat amount, under
an optional cap — set from **Payments › Gateways** and stored in the site
configuration under `payments.fees`. All zero until an owner decides to pass the
card cost on.

`checkout()` adds it to a **purchase** only: the row's amount is the total the vendor
is asked for (so `verify()` checks against the same figure), and the fee is kept as a
`TransactionChargeEnum::FEE` charge row so a receipt can say what it was. A
**deposit** is never charged it — its amount is what lands in the wallet, and a
balance is a sum of amounts.

Tell the customer before they leave for the vendor:
`app(PaymentFeeService::class)->feeFor($vendor, $price)` in the page's `#[Computed]`,
shown next to the total. Never work the fee out anywhere else.

## Adding a vendor

1. A case in `PaymentVendorEnum`, its `label()` arm and its `gateway()` arm.
2. A block under `payments.vendors` in `config/payments.php`, and the env keys in
   `.env.example`.
3. `app/Services/Payment/Gateways/{Vendor}GatewayService.php` extending
   `BasePaymentGateway`, `#[Singleton]`:
   - `requiredKeys()` and `client()`
   - `initialize()` — our reference as the vendor's reference wherever the API takes
     one; return `['url' => …, 'gateway_id' => …]` or an error string
   - `verify()` — map the vendor's statuses onto `StatusTransaction`; return
     `$this->verification(...)`
   - `verifyWebhook()` — the vendor's exact scheme, `hash_equals()` always
   - `parseWebhook()` — the vendor's event id as `event_key` where it sends one,
     `$this->bodyKey($request)` where it does not; map names onto
     `PaymentWebhookTopicEnum`
   - `ping()`
4. Tests in `tests/Feature/PaymentTest.php`: a signature test over the real scheme,
   and a checkout + verify test with `Http::fake()`.

A vendor's signature scheme is the part to read twice. Paystack and Monnify sign the
raw body with SHA-512; Stripe signs `"{t}.{body}"` with SHA-256 and a 5-minute window;
Flutterwave echoes a static hash; PayPal is verified by calling PayPal; OPay uses
**SHA3-512** over a fixed template; Korapay signs only the `data` object, so it is cut
out of the raw body rather than re-encoded.

## Avoid

- Settling a `PAYMENT_GATEWAY` row through `TransactionService::settle()` directly.
- Reading a status, amount or vendor id from the callback's query string.
- Doing the fulfilment inside `PaymentService` — fire-and-listen, so the kit stays
  domain-free.
- A vendor SDK. Ask first; Stripe and PayPal both work over plain HTTP here.
- A public health endpoint. `payments:health` is a command on purpose.
- Logging or tracing a request body without `redact()`.
