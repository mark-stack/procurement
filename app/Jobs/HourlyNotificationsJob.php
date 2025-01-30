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
         */
        $implementations = (new NotificationService)->getImplementations();
        foreach ($implementations as $implementation) {
            $className = 'App\\Services\\NotificationImplementations\\'.$implementation;

            // Check if the class exists
            if (class_exists($className)) {
                $service = new $className;
                $service->hourlyCheck();
            }
        }
    }
}
