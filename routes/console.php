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

/*
 * Deadline reminders. Back on, because the bell is back on and an empty bell is worse than no bell.
 *
 * Switching these off was the only way to stop the emails, since every reminder hard-coded
 * ['mail', 'database'] - so the bell lost its only source of content at the same time. The channels
 * are separate now: this fills the bell, and NOTIFICATIONS_MAIL_REMINDERS decides whether the same
 * reminders also reach an inbox. It ships off. See config/notifications.php.
 *
 * Idempotent per project per window - each implementation's hasBeenNotified() - so running it hourly
 * only makes the warnings timely, never duplicated.
 */
Schedule::job(new HourlyNotificationsJob)->$frequency();

/*
 * Trial expiry warnings. Idempotent - each business gets each reminder once, recorded in
 * notification_logs - so running it more often only makes the warnings more timely, never
 * duplicated. Hourly in test mode for the same reason the notifications job is.
 */
Schedule::command('billing:trial-reminders')->$frequency();
