<?php

use App\Jobs\HourlyNotificationsJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

$testMode = env("TEST_MODE");
$frequency = $testMode ? 'everyMinute' : 'hourly';
Schedule::job(new HourlyNotificationsJob())->$frequency();
