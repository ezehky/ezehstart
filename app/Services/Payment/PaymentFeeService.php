<?php

namespace App\Services\Payment;

use App\Enums\ActivityActionEnum;
use App\Enums\PaymentVendorEnum;
use App\Services\ActivityLogService;
use App\Services\SiteConfigurationService;
use Illuminate\Container\Attributes\Singleton;

/**
 * The processing fee a customer pays on top of a purchase made through a gateway.
 *
 * Set per vendor on Payments › Gateways and zero until somebody does. The fee is
 * worked out here and nowhere else, so the figure a checkout shows before the
 * customer picks a provider is the figure the provider is then asked to charge.
 */
#[Singleton]
class PaymentFeeService
{
    /**
     * The vendor's terms as stored. Read field by field rather than through
     * kSiteConfig()'s default, which would treat a deliberate 0 as "unset".
     *
     * @return array{percent: float, flat: float, cap: float}
     */
    public function termsFor(PaymentVendorEnum $vendor): array
    {
        $terms = (array) kSiteConfig('payments', ['fees', $vendor->value], []);

        return [
            'percent' => max(0, (float) ($terms['percent'] ?? 0)),
            'flat' => max(0, (float) ($terms['flat'] ?? 0)),
            'cap' => max(0, (float) ($terms['cap'] ?? 0)),
        ];
    }

    /**
     * Store one vendor's terms, touching nothing else in the configuration. Read
     * raw so the defaults merged in for display are not written back as though
     * somebody had chosen them. Returns null, or why nothing was saved.
     */
    public function saveTerms(PaymentVendorEnum $vendor, float $percent, float $flat, float $cap): ?string
    {
        if ($vendor->isManual()) {
            return 'A manual payment has no processing fee.';
        }

        $service = app(SiteConfigurationService::class);
        $config = $service->getConfigs(raw: true) ?: [];

        $before = $this->termsFor($vendor);
        $after = ['percent' => round($percent, 2), 'flat' => round($flat, 2), 'cap' => round($cap, 2)];

        // Saving the same terms again is not a change, and not a log line.
        if ($before == $after) {
            return null;
        }

        data_set($config, "payments.fees.{$vendor->value}", $after);

        $service->update($config);

        // Logged with both sides: a fee is money taken from customers, and who
        // changed it and from what is the first question anybody will ask.
        app(ActivityLogService::class)->logActivity(
            ActivityActionEnum::CONFIG_UPDATE,
            " site configuration: {$vendor->label()} processing fee",
            ['original' => $before, 'changes' => $after],
        );

        return null;
    }

    /**
     * The fee on a price, in the default currency. A manual payment has no
     * provider to pay, so it never carries one.
     */
    public function feeFor(PaymentVendorEnum $vendor, float $amount): float
    {
        if ($vendor->isManual() || $amount <= 0) {
            return 0.0;
        }

        $terms = $this->termsFor($vendor);

        $fee = $amount * $terms['percent'] / 100 + $terms['flat'];

        if ($terms['cap'] > 0) {
            $fee = min($fee, $terms['cap']);
        }

        return round($fee, 2);
    }

    /**
     * The terms in one line for a screen — "1.5% + 100, up to 2,000" — or null
     * when the vendor adds nothing.
     */
    public function describe(PaymentVendorEnum $vendor): ?string
    {
        $terms = $this->termsFor($vendor);

        $parts = array_filter([
            $terms['percent'] > 0 ? kMoney($terms['percent']).'%' : null,
            $terms['flat'] > 0 ? kMoneyFormat($terms['flat'], decodeHtml: true, convert: false) : null,
        ]);

        if ($parts === []) {
            return null;
        }

        return implode(' + ', $parts)
            .($terms['cap'] > 0 ? ', up to '.kMoneyFormat($terms['cap'], decodeHtml: true, convert: false) : '');
    }
}
