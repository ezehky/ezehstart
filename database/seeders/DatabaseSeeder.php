<?php

namespace Database\Seeders;

use App\Services\SiteConfigurationService;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database with what an install cannot start without.
     *
     * The policies and the FAQs used to run from here. They are placeholder copy with
     * the site's name dropped into it, and an install that publishes that as its own
     * terms is worse off than one with no terms yet — so they moved to DemoSeeder,
     * alongside the invented people and the invented ledger:
     *
     *     php artisan db:seed --class=DemoSeeder
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
            EmailSectionSeeder::class,
        ];

        $this->call($seeders);
    }
}
