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

/*
 * The offcut rack's dead stock, four times a year.
 *
 * Its own schedule rather than a line in the hourly job: it costs every aged offcut in every yard
 * against the floor for its own section, and the answer does not change between Tuesdays.
 *
 * Idempotent per business per quarter, recorded in notification_logs, so a re-run after a failed
 * night sends nothing twice. It only ever raises a notice - nothing is scrapped until somebody
 * opens the rack and says so. See App\Services\OffcutCleanout.
 */
Schedule::command('offcuts:cleanout')->{$testMode ? 'everyMinute' : 'quarterly'}();

/*
 * The copies of customers' spreadsheets kept against learning attempts nobody picked up.
 *
 * Daily rather than hourly: the window is counted in days and nothing downstream is waiting on it.
 * Not run faster in test mode either - a command whose whole job is deleting a customer's file is
 * the wrong one to have firing every minute on a developer's machine.
 */
Schedule::command('templates:prune-samples')->daily();
