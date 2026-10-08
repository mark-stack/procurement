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
 * The Nesting column, told to get itself quoted when the shop is about to start cutting.
 *
 * It used to do the quoting too. It does not any more - creating a batch nests steel, consumes
 * offcut inventory and commits the business to a purchase, so it waits for somebody to press "Start
 * quoting" - and all that is scheduled here is the warning.
 *
 * Still its own schedule rather than a line in the hourly job above, because deciding whether there
 * is a column at all means reading a business's unbatched pieces through its price book, which is
 * nothing like the per-project date checks that job does. Hourly because the window is counted in
 * days, so a few hours either side of the fifth day costs nobody anything.
 *
 * No longer idempotent by construction either: nothing empties the column now, so the condition is
 * still true an hour later. Each notification holds itself to once a day instead - see
 * App\Services\FabricationDeadlineQuoting.
 */
Schedule::command('quoting:fabrication-deadline')->$frequency();

/*
 * The far end of the board: a batch whose steel has all been booked in becomes a past project.
 *
 * Daily rather than hourly. The window is counted in days, nothing downstream is waiting on it, and
 * the one thing this does cannot be undone by anything in the app - so the slowest cadence that still
 * keeps the board tidy is the right one. Not run faster in test mode either, for the reason the sample
 * prune below is not: a schedule whose job is closing a customer's batch is the wrong one to have
 * firing every minute on a developer's machine.
 *
 * Idempotent: a closed batch is no longer in the Delivering column, so the next run finds nothing. See
 * App\Services\DeliveredBatchAutoDone for the four cases where it leaves a card alone instead.
 */
Schedule::command('batches:mark-delivered-done')->daily();

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
 * Telescope's entries, after a week.
 *
 * The one automatic disposal in this application, and the exception that proves the rule in
 * config/retention.php: nothing on a schedule may delete a *record*, and a Telescope entry is not
 * one. It is debug output - a request, its parameters and its queries, written on the afternoon
 * somebody turned the recorder on - so keeping it would mean keeping a second copy of a customer's
 * data in a table no quality process reads.
 *
 * Guarded on the switch because Telescope ships off in production (config/telescope.php) and
 * pruning a table that was never created is a nightly error in the log for no reason.
 */
if (config('telescope.enabled')) {
    Schedule::command('telescope:prune', ['--hours' => 24 * (int) config('retention.classes.telescope.retain_days')])->daily();
}

/*
 * The copies of customers' spreadsheets kept against learning attempts nobody picked up.
 *
 * Daily rather than hourly: the window is counted in days and nothing downstream is waiting on it.
 * Not run faster in test mode either - a command whose whole job is deleting a customer's file is
 * the wrong one to have firing every minute on a developer's machine.
 */
Schedule::command('templates:prune-samples')->daily();
