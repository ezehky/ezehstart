<?php

namespace Database\Seeders;

use App\Services\DashboardManagerService;
use App\Services\ImageLibraryService;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

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
        $this->clearUploadedImages();
        $this->clearDashboardArrangements();

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

    /**
     * Empty the image library off disk before anything is seeded.
     *
     * migrate:fresh drops the tables but never touches the filesystem, so every
     * re-seed left the previous run's uploads behind: files under library/ that
     * no row points at any more, piling up one run at a time. The whole
     * directory goes rather than one row at a time, because by the time this
     * runs the rows that named those files are already gone.
     *
     * Only the library is cleared. site-config/ holds the install's own logo and
     * favicon, which are not demo data and not this seeder's to remove.
     */
    protected function clearUploadedImages(): void
    {
        Storage::disk('public')->deleteDirectory(ImageLibraryService::STORAGE_PATH);
    }

    /**
     * Empty the dashboard-manager files too.
     *
     * Same problem as the library, with a sharper edge: these are named after the
     * account id, and migrate:fresh restarts ids from one. Left alone, demo user
     * 3 opens the dashboard and inherits whatever the last run's user 3 had
     * hidden — an arrangement nobody on this install ever chose, and one that
     * looks like a bug in the column manager rather than a stale file.
     *
     * Written against the default disk rather than a named one, because that is
     * what DashboardManagerService itself reads and writes.
     */
    protected function clearDashboardArrangements(): void
    {
        Storage::deleteDirectory(DashboardManagerService::STORAGE_PATH);
    }
}
