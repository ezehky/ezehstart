<?php

namespace App\Services\Payment;

use App\Enums\ActivityActionEnum;
use App\Enums\PaymentVendorEnum;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\SiteConfigurationService;
use Illuminate\Container\Attributes\Singleton;

/**
 * The processing fee a customer pays on top of a purchase made through a gateway.
 *
 * Set per vendor on Payments › Gateways and zero until somebody does. The fee is
 * worked out here and nowhere else, so the figure a checkout shows before the
 * customer picks a provider is the figure the provider is then asked to charge.
 *
 * A vendor can carry a second set of terms for cards issued abroad, which most
 * of them price higher. The vendor only says where a card is from once it has
 * been charged, and the fee is on the row before that, so which terms apply is
 * decided up front: by the caller when it knows (the customer said), otherwise
 * by the account's country against the home country set beside the fees.
 */
#[Singleton]
class PaymentFeeService
{
    /**
     * The vendor's terms as stored. Read field by field rather than through
     * kSiteConfig()'s default, which would treat a deliberate 0 as "unset".
     *
     * International terms fall back to the local ones while none are set, so
     * a vendor priced the same everywhere needs nothing more.
     *
     * @return array{percent: float, flat: float, cap: float}
     */
    public function termsFor(PaymentVendorEnum $vendor, bool $international = false): array
    {
        $terms = (array) kSiteConfig('payments', ['fees', $vendor->value], []);

        if ($international && \is_array($terms['international'] ?? null)) {
            $terms = $terms['international'];
        }

        return [
            'percent' => max(0.0, (float) ($terms['percent'] ?? 0)),
            'flat' => max(0.0, (float) ($terms['flat'] ?? 0)),
            'cap' => max(0.0, (float) ($terms['cap'] ?? 0)),
        ];
    }

    /**
     * Whether the vendor prices a card from abroad apart from a local one.
     */
    public function hasInternationalTerms(PaymentVendorEnum $vendor): bool
    {
        return \is_array(kSiteConfig('payments', ['fees', $vendor->value, 'international'], null));
    }

    /**
     * Store one vendor's terms, touching nothing else in the configuration. Read
     * raw so the defaults merged in for display are not written back as though
     * somebody had chosen them. International terms of null mean the vendor
     * charges every card the same. Returns null, or why nothing was saved.
     *
     * @param  ?array{percent: float, flat: float, cap: float}  $international
     */
    public function saveTerms(PaymentVendorEnum $vendor, float $percent, float $flat, float $cap, ?array $international = null): ?string
    {
        if ($vendor->isManual()) {
            return 'A manual payment has no processing fee.';
        }

        $service = app(SiteConfigurationService::class);
        $config = $service->getConfigs(raw: true) ?: [];

        $before = [
            ...$this->termsFor($vendor),
            'international' => $this->hasInternationalTerms($vendor) ? $this->termsFor($vendor, true) : null,
        ];

        $after = [
            'percent' => round($percent, 2),
            'flat' => round($flat, 2),
            'cap' => round($cap, 2),
            'international' => $international === null ? null : [
                'percent' => round(max(0, (float) $international['percent']), 2),
                'flat' => round(max(0, (float) $international['flat']), 2),
                'cap' => round(max(0, (float) $international['cap']), 2),
            ],
        ];

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
     * The country cards count as local to, as an ISO 3166-1 alpha-2 code. Null
     * until somebody sets it, and while it is null no card is taken for a
     * foreign one — the local terms apply to everybody.
     */
    public function homeCountry(): ?string
    {
        $code = strtoupper(trim((string) kSiteConfig('payments', ['home-country'], '')));

        return \strlen($code) === 2 ? $code : null;
    }

    /**
     * Store the home country, the same raw way as the terms. Returns null, or
     * why nothing was saved.
     */
    public function saveHomeCountry(?string $iso2): ?string
    {
        $iso2 = filled($iso2) ? strtoupper(trim($iso2)) : null;

        if ($iso2 !== null && \strlen($iso2) !== 2) {
            return 'Pick a country from the list.';
        }

        $before = $this->homeCountry();

        if ($before === $iso2) {
            return null;
        }

        $service = app(SiteConfigurationService::class);
        $config = $service->getConfigs(raw: true) ?: [];

        data_set($config, 'payments.home-country', $iso2);

        $service->update($config);

        app(ActivityLogService::class)->logActivity(
            ActivityActionEnum::CONFIG_UPDATE,
            ' site configuration: payments home country',
            ['original' => ['home-country' => $before], 'changes' => ['home-country' => $iso2]],
        );

        return null;
    }

    /**
     * Whether this account's card is presumed to be from abroad: its country —
     * the one on its profile, or failing that the one its phone number was
     * picked from — is not the home country. An account that has said
     * neither is presumed local, the cheaper guess, so nobody is charged the
     * dearer fee on no evidence.
     */
    public function isInternational(User $user): bool
    {
        $home = $this->homeCountry();

        if ($home === null) {
            return false;
        }

        $country = $user->userProfile?->country?->iso2 ?: $user->phone_iso2;

        return filled($country) && strtoupper((string) $country) !== $home;
    }

    /**
     * The fee on a price, in the default currency. A manual payment has no
     * provider to pay, so it never carries one.
     */
    public function feeFor(PaymentVendorEnum $vendor, float $amount, bool $international = false): float
    {
        if ($vendor->isManual() || $amount <= 0) {
            return 0.0;
        }

        $terms = $this->termsFor($vendor, $international);

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
    public function describe(PaymentVendorEnum $vendor, bool $international = false): ?string
    {
        $terms = $this->termsFor($vendor, $international);

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
