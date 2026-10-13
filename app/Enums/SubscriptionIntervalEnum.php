<?php

namespace App\Enums;

use App\Traits\WithEnumHelpers;
use Carbon\CarbonInterface;

/**
 * How often a plan bills.
 *
 * Every vendor names its intervals differently, so each one's spelling lives
 * here beside ours rather than in seven gateway services.
 */
enum SubscriptionIntervalEnum: string
{
    use WithEnumHelpers;

    case DAILY = 'daily';
    case WEEKLY = 'weekly';
    case MONTHLY = 'monthly';
    case QUARTERLY = 'quarterly';
    case BIANNUALLY = 'biannually';
    case ANNUALLY = 'annually';

    public function label(bool $lowercase = false): string
    {
        $label = match ($this) {
            self::DAILY => 'Daily',
            self::WEEKLY => 'Weekly',
            self::MONTHLY => 'Monthly',
            self::QUARTERLY => 'Every 3 months',
            self::BIANNUALLY => 'Every 6 months',
            self::ANNUALLY => 'Yearly',
        };

        return $lowercase ? strtolower($label) : $label;
    }

    /**
     * Reads after a price: "₦5,000 / month".
     */
    public function perLabel(): string
    {
        return match ($this) {
            self::DAILY => 'day',
            self::WEEKLY => 'week',
            self::MONTHLY => 'month',
            self::QUARTERLY => '3 months',
            self::BIANNUALLY => '6 months',
            self::ANNUALLY => 'year',
        };
    }

    /**
     * The unit and count, the shape Stripe and PayPal both take.
     *
     * @return array{0: string, 1: int}
     */
    public function unitAndCount(): array
    {
        return match ($this) {
            self::DAILY => ['day', 1],
            self::WEEKLY => ['week', 1],
            self::MONTHLY => ['month', 1],
            self::QUARTERLY => ['month', 3],
            self::BIANNUALLY => ['month', 6],
            self::ANNUALLY => ['year', 1],
        };
    }

    /**
     * The average length in days — only for comparing prices across intervals
     * ("save 2% yearly"), never for working out a date. addTo() does that.
     */
    public function averageDays(): float
    {
        return match ($this) {
            self::DAILY => 1,
            self::WEEKLY => 7,
            self::MONTHLY => 365.25 / 12,
            self::QUARTERLY => 365.25 / 4,
            self::BIANNUALLY => 365.25 / 2,
            self::ANNUALLY => 365.25,
        };
    }

    /**
     * Paystack's spelling. It has every one of ours under the same names.
     */
    public function paystackInterval(): string
    {
        return $this->value;
    }

    public function flutterwaveInterval(): string
    {
        return match ($this) {
            self::BIANNUALLY => 'bi-anually',
            self::ANNUALLY => 'yearly',
            default => $this->value,
        };
    }

    /**
     * The end of a period that starts at $from — the fallback when a vendor does
     * not say when the next charge is due.
     */
    public function addTo(CarbonInterface $from): CarbonInterface
    {
        [$unit, $count] = $this->unitAndCount();

        return match ($unit) {
            'day' => $from->copy()->addDays($count),
            'week' => $from->copy()->addWeeks($count),
            'month' => $from->copy()->addMonthsNoOverflow($count),
            'year' => $from->copy()->addYearsNoOverflow($count),
        };
    }
}
