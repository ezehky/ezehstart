<?php

namespace App\Enums;

use App\Contracts\PaymentGateway;
use App\Services\Payment\Gateways\FlutterwaveGatewayService;
use App\Services\Payment\Gateways\KorapayGatewayService;
use App\Services\Payment\Gateways\MonnifyGatewayService;
use App\Services\Payment\Gateways\OpayGatewayService;
use App\Services\Payment\Gateways\PaypalGatewayService;
use App\Services\Payment\Gateways\PaystackGatewayService;
use App\Services\Payment\Gateways\StripeGatewayService;
use App\Traits\WithEnumHelpers;

/**
 * The processor behind a transaction_gateways row.
 *
 * MANUAL is the case that always applies: a bank transfer reviewed by an
 * administrator is still a gateway row, and giving it a vendor saves every
 * reconciliation query from special-casing a null.
 *
 * Every other case is a gateway with a service behind it. Adding a vendor is a
 * case here, its block in config/payments.php, and a {Vendor}GatewayService
 * implementing PaymentGateway — the match below fails loudly until all three
 * exist.
 */
enum PaymentVendorEnum: string
{
    use WithEnumHelpers;

    case MANUAL = 'manual';
    case PAYSTACK = 'paystack';
    case FLUTTERWAVE = 'flutterwave';
    case STRIPE = 'stripe';
    case PAYPAL = 'paypal';
    case OPAY = 'opay';
    case MONNIFY = 'monnify';
    case KORAPAY = 'korapay';

    public function isManual(): bool
    {
        return $this === self::MANUAL;
    }

    public function isPaystack(): bool
    {
        return $this === self::PAYSTACK;
    }

    public function isFlutterwave(): bool
    {
        return $this === self::FLUTTERWAVE;
    }

    public function isStripe(): bool
    {
        return $this === self::STRIPE;
    }

    public function isPaypal(): bool
    {
        return $this === self::PAYPAL;
    }

    public function isOpay(): bool
    {
        return $this === self::OPAY;
    }

    public function isMonnify(): bool
    {
        return $this === self::MONNIFY;
    }

    public function isKorapay(): bool
    {
        return $this === self::KORAPAY;
    }

    /**
     * Brand names are not title case, and "Paypal" on a checkout button reads
     * as a phishing page.
     */
    public function label(bool $lowercase = false): string
    {
        $label = match ($this) {
            self::MANUAL => 'Bank transfer',
            self::PAYSTACK => 'Paystack',
            self::FLUTTERWAVE => 'Flutterwave',
            self::STRIPE => 'Stripe',
            self::PAYPAL => 'PayPal',
            self::OPAY => 'OPay',
            self::MONNIFY => 'Monnify',
            self::KORAPAY => 'Korapay',
        };

        return $lowercase ? strtolower($label) : $label;
    }

    /**
     * The service that talks to this vendor. Null for MANUAL, which has nobody
     * on the other end to call.
     */
    public function gateway(): ?PaymentGateway
    {
        $class = match ($this) {
            self::MANUAL => null,
            self::PAYSTACK => PaystackGatewayService::class,
            self::FLUTTERWAVE => FlutterwaveGatewayService::class,
            self::STRIPE => StripeGatewayService::class,
            self::PAYPAL => PaypalGatewayService::class,
            self::OPAY => OpayGatewayService::class,
            self::MONNIFY => MonnifyGatewayService::class,
            self::KORAPAY => KorapayGatewayService::class,
        };

        return $class ? app($class) : null;
    }

    /**
     * Keys live in the environment rather than the site configuration, so a vendor
     * is only offered once someone has actually filled them in.
     */
    public function isConfigured(): bool
    {
        return $this->isManual() || (bool) $this->gateway()?->isConfigured();
    }

    /**
     * Configured, and not switched off. What a checkout offers.
     */
    public function isAvailable(): bool
    {
        if ($this->isManual()) {
            return true;
        }

        return (bool) config("payments.vendors.{$this->value}.enabled", true) &&
        $this->isConfigured();
    }

    /**
     * The gateways a checkout can offer right now, the configured default first.
     *
     * @return array<int, self>
     */
    public static function checkoutOptions(): array
    {
        $available = array_values(array_filter(
            self::cases(),
            fn (self $case) => ! $case->isManual() && $case->isAvailable(),
        ));

        usort($available, fn (self $first, self $second) => (int) ($second->value === config('payments.default'))
            - (int) ($first->value === config('payments.default')));

        return $available;
    }
}
