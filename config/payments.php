<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default vendor
    |--------------------------------------------------------------------------
    |
    | The gateway preselected on a checkout when more than one is configured.
    | A PaymentVendorEnum value. A vendor with no keys is never offered, so a
    | default pointing at one simply falls through to the first that has them.
    |
    */

    'default' => env('PAYMENTS_DEFAULT_VENDOR', 'paystack'),

    /*
    |--------------------------------------------------------------------------
    | Vendors
    |--------------------------------------------------------------------------
    |
    | One block per PaymentVendorEnum case, keyed by the case value. The keys
    | live here rather than in the site configuration for the same reason the
    | captcha secret does: site-configuration.json is an administrator-editable
    | file, and a secret an admin screen can read back is not a secret.
    |
    | Each gateway service says which of its keys are required, and a vendor is
    | only offered once all of them are filled in. `enabled` turns a configured
    | vendor off without deleting its keys.
    |
    | `sandbox` picks the test host for the vendors that use a separate one
    | (PayPal, OPay, Monnify). Paystack, Flutterwave, Stripe and Korapay tell
    | test from live by the key itself.
    |
    */

    'vendors' => [

        'paystack' => [
            'enabled' => (bool) env('PAYSTACK_ENABLED', true),
            'public' => env('PAYSTACK_PUBLIC_KEY'),
            'secret' => env('PAYSTACK_SECRET_KEY'),
        ],

        'flutterwave' => [
            'enabled' => (bool) env('FLUTTERWAVE_ENABLED', true),
            'public' => env('FLUTTERWAVE_PUBLIC_KEY'),
            'secret' => env('FLUTTERWAVE_SECRET_KEY'),

            // The "secret hash" set on the Flutterwave dashboard, echoed back in
            // the verif-hash header of every webhook.
            'webhook_hash' => env('FLUTTERWAVE_WEBHOOK_HASH'),
        ],

        'stripe' => [
            'enabled' => (bool) env('STRIPE_ENABLED', true),
            'public' => env('STRIPE_PUBLIC_KEY'),
            'secret' => env('STRIPE_SECRET_KEY'),

            // The whsec_ signing secret of the webhook endpoint.
            'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        ],

        'paypal' => [
            'enabled' => (bool) env('PAYPAL_ENABLED', true),
            'sandbox' => (bool) env('PAYPAL_SANDBOX', true),
            'client_id' => env('PAYPAL_CLIENT_ID'),
            'secret' => env('PAYPAL_SECRET'),

            // The id PayPal gives the webhook when it is registered. Signature
            // verification is a call back to PayPal that has to name it.
            'webhook_id' => env('PAYPAL_WEBHOOK_ID'),
        ],

        'opay' => [
            'enabled' => (bool) env('OPAY_ENABLED', true),
            'sandbox' => (bool) env('OPAY_SANDBOX', true),
            'merchant_id' => env('OPAY_MERCHANT_ID'),
            'public' => env('OPAY_PUBLIC_KEY'),
            'secret' => env('OPAY_SECRET_KEY'),
            'country' => env('OPAY_COUNTRY', 'NG'),
            'base_url' => env('OPAY_BASE_URL'),
        ],

        'monnify' => [
            'enabled' => (bool) env('MONNIFY_ENABLED', true),
            'sandbox' => (bool) env('MONNIFY_SANDBOX', true),
            'api_key' => env('MONNIFY_API_KEY'),
            'secret' => env('MONNIFY_SECRET_KEY'),
            'contract_code' => env('MONNIFY_CONTRACT_CODE'),
        ],

        'korapay' => [
            'enabled' => (bool) env('KORAPAY_ENABLED', true),
            'public' => env('KORAPAY_PUBLIC_KEY'),
            'secret' => env('KORAPAY_SECRET_KEY'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Checkout
    |--------------------------------------------------------------------------
    |
    | How long a checkout link is offered for before the page asks for a fresh
    | one. Vendors that accept an expiry are told the same number, so the link
    | and the gateway agree on when it dies.
    |
    */

    'checkout' => [
        'expires_in_minutes' => (int) env('PAYMENTS_CHECKOUT_EXPIRY', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhooks
    |--------------------------------------------------------------------------
    |
    | Verification is on, and stays on. Turning it off is honoured in the local
    | and testing environments only, for sandboxes that send unsigned calls —
    | anywhere else the switch is ignored, because an unsigned webhook is anybody
    | on the internet telling the ledger that they paid.
    |
    | `allowed_ips` is an optional second fence. Empty means any address, which
    | is the right default: the signature is what proves who sent it.
    |
    */

    'webhooks' => [
        'verify_signature' => (bool) env('PAYMENTS_WEBHOOK_VERIFY_SIGNATURE', true),
        'allowed_ips' => array_filter(explode(',', (string) env('PAYMENTS_WEBHOOK_ALLOWED_IPS', ''))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | Webhooks are acknowledged straight away and worked on a queue, because a
    | vendor that waits on a slow response retries, and a retry is a duplicate
    | to be thrown away. Null connection means the application default.
    |
    */

    'queue' => [
        'connection' => env('PAYMENTS_QUEUE_CONNECTION'),
        'name' => env('PAYMENTS_QUEUE', 'default'),
        'tries' => (int) env('PAYMENTS_QUEUE_TRIES', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Reconciliation
    |--------------------------------------------------------------------------
    |
    | payments:reconcile asks the vendor about every checkout still processing
    | after `after_minutes` — the safety net for a webhook that never arrived.
    | A checkout the vendor still reports unpaid `abandon_after_hours` after it
    | expired is closed as cancelled.
    |
    */

    'reconcile' => [
        'after_minutes' => (int) env('PAYMENTS_RECONCILE_AFTER', 15),
        'abandon_after_hours' => (int) env('PAYMENTS_ABANDON_AFTER', 24),
    ],

    /*
    |--------------------------------------------------------------------------
    | Tracing
    |--------------------------------------------------------------------------
    |
    | A row per call to a vendor — what was asked, what came back, how long it
    | took — with credentials and card details stripped. Off by default: it is
    | for diagnosing an integration, and it grows quickly once traffic is real.
    |
    */

    'tracing' => [
        'enabled' => (bool) env('PAYMENTS_TRACING', false),
        'retention_days' => (int) env('PAYMENTS_TRACING_RETENTION', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP
    |--------------------------------------------------------------------------
    */

    'http' => [
        'timeout' => (int) env('PAYMENTS_HTTP_TIMEOUT', 30),
        'retries' => (int) env('PAYMENTS_HTTP_RETRIES', 2),
    ],

];
