<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Everything a developer wants on screen and nothing an install needs.
 *
 *     php artisan db:seed --class=DemoSeeder
 *
 * Deliberately absent from DatabaseSeeder. A fresh install gets roles, countries,
 * notification types and one administrator; it does not get twenty invented people, a
 * ledger full of invented money, or placeholder legal copy nobody wrote. Those are for
 * looking at a screen that has something in it.
 *
 * The policies and FAQs are here for that reason: seeded copy is placeholder text with
 * the site's name dropped into it, and a live install publishing it as its terms is
 * worse than an install with no terms yet.
 *
 * Run it as often as you like — every seeder under it matches on a natural key, so a
 * second run refreshes rather than doubles.
 */
class DemoSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call([
            // Copy first: both quote the site's own name and contact details.
            PolicySeeder::class,
            FaqSeeder::class,

            // People before the things that belong to them.
            DemoUserSeeder::class,
            DemoContentSeeder::class,
            DemoTransactionSeeder::class,
        ]);
    }
}
