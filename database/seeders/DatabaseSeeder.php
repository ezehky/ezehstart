<?php

namespace Database\Seeders;

use App\Services\SiteConfigurationService;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $seeders = [];

        // The site config is written to storage rather than a table, so it is only
        // laid down the first time — re-seeding must not wipe what an admin saved.
        //
        // The check reads the stored file, not kSiteConfig(): the cached copy always
        // carries resolved logo and support-link keys, so it is never empty and would
        // make this guard always true.
        if (! app(SiteConfigurationService::class)->getConfigs(raw: true)) {
            $seeders[] = SiteConfigSeeder::class;
        }

        $seeders = [
            ...$seeders,
            RoleSeeder::class,
            // Before UserSeeder: the member dashboard backfills a preference row
            // per active type, and with no types seeded it would quietly write
            // none and look like the feature was broken.
            NotificationTypeSeeder::class,
            CountrySeeder::class,
            UserSeeder::class,
            // Both match on natural keys rather than ids, so re-seeding refreshes
            // the copy instead of stacking up duplicates. PolicySeeder only ever
            // writes version 1.0 — once a real 2.0 is published it stops being
            // what the public page shows.
            PolicySeeder::class,
            FaqSeeder::class,
        ];

        $this->call($seeders);
    }
}
