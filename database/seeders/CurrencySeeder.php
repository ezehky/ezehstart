<?php

namespace Database\Seeders;

use App\Enums\StatusDefault;
use App\Enums\StatusYes;
use App\Models\Currency;
use App\Services\CurrencyService;
use Illuminate\Database\Seeder;

class CurrencySeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * The naira is the default because it is what kMoneyFormat() printed before
     * currencies were rows, so an install that never opens the currency screen
     * reads exactly as it did. Rates are placeholders against the naira — an
     * administrator sets real ones before anybody is shown a converted amount.
     *
     * Matched on code, so re-seeding updates in place, and only a missing row is
     * written: a rate or status somebody changed survives the next deploy.
     */
    public function run(): void
    {
        $currencies = [
            ['name' => 'Naira', 'code' => 'NGN', 'symbol' => '&#8358;', 'rate' => 1, 'is_default' => StatusYes::YES],
            ['name' => 'US Dollar', 'code' => 'USD', 'symbol' => '&#36;', 'rate' => 0.00065],
            ['name' => 'Pound Sterling', 'code' => 'GBP', 'symbol' => '&#163;', 'rate' => 0.00049],
            ['name' => 'Euro', 'code' => 'EUR', 'symbol' => '&euro;', 'rate' => 0.00057],
            ['name' => 'Ghanaian Cedi', 'code' => 'GHS', 'symbol' => '&#8373;', 'rate' => 0.0078],
            ['name' => 'Kenyan Shilling', 'code' => 'KES', 'symbol' => 'KSh', 'rate' => 0.084, 'status' => StatusDefault::INACTIVE],
            ['name' => 'South African Rand', 'code' => 'ZAR', 'symbol' => 'R', 'rate' => 0.012, 'status' => StatusDefault::INACTIVE],
            ['name' => 'Canadian Dollar', 'code' => 'CAD', 'symbol' => 'CA&#36;', 'rate' => 0.00089, 'status' => StatusDefault::INACTIVE],
            ['name' => 'Indian Rupee', 'code' => 'INR', 'symbol' => '&#8377;', 'rate' => 0.054, 'status' => StatusDefault::INACTIVE],
            ['name' => 'UAE Dirham', 'code' => 'AED', 'symbol' => 'AED', 'rate' => 0.0024, 'status' => StatusDefault::INACTIVE],
        ];

        foreach ($currencies as $currency) {
            Currency::query()->firstOrCreate(
                ['code' => $currency['code']],
                [
                    'is_default' => StatusYes::NO,
                    'status' => StatusDefault::ACTIVE,
                    ...$currency,
                ],
            );
        }

        // The cached default was resolved before any of this existed.
        app(CurrencyService::class)->flush();
    }
}
