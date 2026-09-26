<?php

namespace App\Services;

use App\Models\Project;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\File;

class NotificationService
{
    public function getUnreadNotifications(?object $user = null): array
    {
        $implementations = (new NotificationService)->getImplementations();

        $notifications = [];

        if ($user) {
            foreach ($user->unreadNotifications as $notification) {
                //Loop through all interface implementations
                foreach ($implementations as $implementation) {
                    $className = 'App\\Services\\NotificationImplementations\\'.$implementation;

                    // Check if the class exists
                    if (class_exists($className)) {
                        $service = new $className;

                        $notificationData = $service->notificationData($notification);

                        if ($notificationData) {
                            $notifications[] = $notificationData;
                        }
                    }
                }

                /**
                 * Quotes
                 */
                /*
                 * Quote overdue
                 */

                /*
                 * Did you send the quote?
                 */

                /*
                 * Did you receive & file the quote response?
                 */

                /*
                 * Quote by a colleague
                 */

                /**
                 * Orders
                 */

                /*
                 * Order due
                 */

                /*
                 * Order overdue
                 */

                /*
                 * Did you place the order?
                 */

                /*
                 * Did you receive order confirmation?
                 */

                /*
                 * Order by a colleague
                 */

                /*
                 * Order approval required
                 */

                //todo more
            }
        }

        return $notifications;
    }

    public function getImplementations(): array
    {
        $exclude = 'ProductBaseImplementation';

        $directory = app_path('Services/NotificationImplementations');

        return collect(File::files($directory))
            ->map(function ($file) {
                return $file->getFilename();
            })
            ->map(function ($filename) {
                return pathinfo($filename, PATHINFO_FILENAME);
            })
            ->filter(function ($className) use ($exclude) {
                // Exclude the specified class
                return $className !== $exclude;
            })
            ->values()
            ->toArray();
    }

    public function clearProjectNotifications(Project $project): void
    {
        /**
         * Everything still outstanding about one project, marked read. Each implementation
         * clears its own notification when the thing it asks about is answered - awarded,
         * tentative date confirmed - and none of them treats archiving as an answer, so a
         * project taken off the board kept asking whether it had been awarded. The hourly
         * checks would eventually have tidied up behind them, and those are switched off.
         */
        DatabaseNotification::query()
            ->where('notifiable_type', "App\Models\User")
            ->where('data->project_id', $project->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    public function clearPreviousNotifications(string $class): void
    {
        $classWithPath = "App\Notifications\\".$class;

        DatabaseNotification::query()
            ->where('type', $classWithPath)
            ->where('notifiable_type', "App\Models\User")
            ->update(['read_at' => now()]);
    }
}
