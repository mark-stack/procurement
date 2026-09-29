<?php

namespace App\Jobs;

use App\Services\NotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

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
            $implementation->hourlyCheck();
        }
    }
}
