<?php

namespace Database\Seeders;

use App\Models\Country;
use Illuminate\Database\Seeder;

class CountrySeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * The list is a file in the repository rather than a download at seed time.
     * `composer setup` has to work on a laptop with no network and in a CI job
     * with no egress, and a seeder that reaches the internet turns a first-run
     * install into a coin toss on somebody else's uptime.
     *
     * Refresh it from the dr5hn/countries-states-cities-database dataset when a
     * country changes; matched on iso2, so re-seeding updates in place.
     */
    public function run(): void
    {
        $path = database_path('data/countries.json');

        if (! is_file($path)) {
            $this->command?->warn('database/data/countries.json is missing — skipping countries.');

            return;
        }

        $countries = json_decode((string) file_get_contents($path), true);

        if (! \is_array($countries)) {
            $this->command?->warn('database/data/countries.json could not be read — skipping countries.');

            return;
        }

        // One statement instead of 250. upsert() matches on the unique iso2 and
        // leaves status alone, so a country an administrator switched off stays
        // off through the next deploy.
        $rows = [];

        foreach ($countries as $country) {
            if (! data_get($country, 'iso2')) {
                continue;
            }

            $rows[] = [
                'name' => data_get($country, 'name'),
                'iso2' => data_get($country, 'iso2'),
                'iso3' => data_get($country, 'iso3'),
                'phone_code' => data_get($country, 'phone_code'),
                'currency' => data_get($country, 'currency'),
                'currency_symbol' => data_get($country, 'currency_symbol'),
                'flag' => data_get($country, 'flag'),
            ];
        }

        foreach (array_chunk($rows, 100) as $chunk) {
            Country::upsert(
                $chunk,
                ['iso2'],
                ['name', 'iso3', 'phone_code', 'currency', 'currency_symbol', 'flag']
            );
        }
    }
}
