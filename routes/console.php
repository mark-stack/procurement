<?php

use App\Jobs\HourlyNotificationsJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

$testMode = config('env.test_mode');
$frequency = $testMode ? 'everyMinute' : 'hourly';
//Schedule::job(new HourlyNotificationsJob)->$frequency();

/*
 * Trial expiry warnings. Idempotent - each business gets each reminder once, recorded in
 * notification_logs - so running it more often only makes the warnings more timely, never
 * duplicated. Hourly in test mode for the same reason the notifications job is.
 */
Schedule::command('billing:trial-reminders')->$frequency();
