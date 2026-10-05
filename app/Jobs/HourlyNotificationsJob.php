<?php

namespace App\Jobs;

use App\Services\NotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class HourlyNotificationsJob implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        /**
         * Don't stack up notifications.
         * To “re-remind”, mark the previous as read and create new one
         *
         * The event-driven implementations on the list have an empty hourlyCheck(), so this is the
         * three deadline reminders and nothing else.
         */
        foreach ((new NotificationService)->hourlyImplementations() as $implementation) {
            try {
                $implementation->hourlyCheck();
            } catch (Throwable $e) {
                /*
                 * One reminder's bad data does not stop the other two, the way one business's does
                 * not stop the rest in FabricationDeadlineQuoting::warnAll.
                 *
                 * This list runs in order and ran unguarded, so anything thrown out of the first
                 * implementation silently cancelled every reminder behind it, for every business, on
                 * a schedule nobody is watching. It took one project with a tentative date and no
                 * materials date to do it - a null into a string argument - which is the shape of
                 * failure this is here for: not the reminder that is known to be fragile, but the one
                 * that is not.
                 */
                Log::error('Hourly notification check failed', [
                    'implementation' => $implementation::class,
                    'exception' => $e,
                ]);
            }
        }
    }
}
