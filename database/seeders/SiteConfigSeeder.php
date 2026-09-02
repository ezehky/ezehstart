<?php

namespace Database\Seeders;

use App\Services\SiteConfigurationService;
use Illuminate\Database\Seeder;

class SiteConfigSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        app(SiteConfigurationService::class)->update(initials: true);
    }
}
