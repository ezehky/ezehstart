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

/*
|--------------------------------------------------------------------------
| Blog schedule
|--------------------------------------------------------------------------
|
| Every minute, because a post scheduled for 09:00 that appears at 09:15 has
| missed the thing it was scheduled for. The command claims each post through
| a conditional update, so overlapping ticks cannot publish one twice.
|
*/

Schedule::command('blog:publish-scheduled')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

/*
|--------------------------------------------------------------------------
| Email campaign schedule
|--------------------------------------------------------------------------
|
| Every minute for the same reason as the blog schedule — a campaign
| scheduled for 10:00 that starts at 10:15 has missed its moment. The same
| tick also works through every campaign already sending, in chunks, so a
| large audience is delivered over several ticks rather than one long request.
|
*/

Schedule::command('email:send-campaigns')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

/*
|--------------------------------------------------------------------------
| Audit trail retention
|--------------------------------------------------------------------------
|
| Off unless somebody sets a window on the security screen, because throwing
| away an audit trail is a decision rather than a default. Runs before the
| account sweep so the entries the sweep writes are never the ones it prunes.
|
*/

Schedule::command('activity:prune-logs')
    ->dailyAt('07:45')
    ->withoutOverlapping()
    ->runInBackground();
