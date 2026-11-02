<?php

namespace Database\Seeders;

use App\Models\Country;
use App\Models\Region;
use Illuminate\Database\Seeder;

class RegionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Every country's top-level states and regions, from the same
     * dr5hn/countries-states-cities-database dataset as the countries — kept in
     * the repository so seeding needs no network (see CountrySeeder). Refresh it
     * from that dataset's states.json, keeping rows with no parent_id, and only
     * the country code and name.
     *
     * Only ever created, never updated: a region an admin renamed or switched
     * off stays as they left it.
     */
    public function run(): void
    {
        $path = database_path('data/regions.json');

        if (! is_file($path)) {
            $this->command?->warn('database/data/regions.json is missing — skipping regions.');

            return;
        }

        $rows = json_decode((string) file_get_contents($path), true);

        if (! \is_array($rows)) {
            $this->command?->warn('database/data/regions.json could not be read — skipping regions.');

            return;
        }

        $countries = Country::query()->pluck('id', 'iso2');

        collect($rows)
            ->filter(fn (array $row) => isset($countries[$row['country']]) && filled($row['name']))
            ->map(fn (array $row) => ['country_id' => $countries[$row['country']], 'name' => mb_substr(trim($row['name']), 0, 100)])
            ->unique(fn (array $row) => $row['country_id'].'|'.mb_strtolower($row['name']))
            ->chunk(500)
            // The unique index on country and name is what makes a re-seed skip
            // the rows already there.
            ->each(fn ($chunk) => Region::query()->insertOrIgnore($chunk->values()->all()));
    }
}
