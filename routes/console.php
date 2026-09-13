<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Account deletion schedule
|--------------------------------------------------------------------------
|
| The warnings go out before the sweep runs, and in the same tick, so nobody
| is ever told their account goes tomorrow by a run that then deletes it.
| Both are daily because the grace period is measured in days — running them
| more often would only mean more chances to find nothing.
|
*/

Schedule::command('account:send-deletion-reminders')
    ->dailyAt('08:00')
    ->withoutOverlapping();

Schedule::command('account:process-deletions')
    ->dailyAt('08:15')
    ->withoutOverlapping();
