<?php

namespace App\Services;

use Illuminate\Support\Facades\File;

class NotificationService
{
    public function getNotifications(object $user = null): array
    {
        $implementations = (new NotificationService())->getImplementations();
        $notifications = [];

        if($user){
            foreach ($user->unreadNotifications as $notification) {

                //Loop through all interface implementations
                foreach($implementations as $implementation){
                    $className = 'App\\Services\\NotificationImplementations\\'.$implementation;

                    // Check if the class exists
                    if (class_exists($className)) {
                        $service = new $className();

                        $notificationData = $service->notificationData($notification);

                        if($notificationData){
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
        $directory = app_path('Services/NotificationImplementations');
        return collect(File::files($directory))
            ->map(function ($file) {
                return $file->getFilename();
            })
            ->map(function ($filename) {
                return pathinfo($filename, PATHINFO_FILENAME);
            })
            ->values()
            ->toArray();
    }
}
