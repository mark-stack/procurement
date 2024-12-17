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
                    $className = 'App\\Services\\Interfaces\\'.$implementation;

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
                 * A colleague joined
                 */
//                if($notification->type === "App\Notifications\NewUserEmail"){
//
//                    $name = $notification->data["new_user_name"] ?? null;
//
//                    $notifications[] = [
//                        "id" => $notification->id,
//                        "message" => $name." recently joined. You can now batch orders together.",
//                        "timestamp" => $notification->created_at->diffForHumans(),
//                        "yesNoMaybe" => null,
//                    ];
//                }

                /**
                 * Projects
                 */
                /*
                 * Has the project been awarded to you?
                 */
//                if($notification->type === "App\Notifications\ProjectAwardedCheckEmail"){
//                    $project_name = $notification->data["project_name"] ?? null;
//
//                    $notifications[] = [
//                        "id" => $notification->id,
//                        "message" => "Has the project '".$project_name."' been awarded to you?",
//                        "timestamp" => $notification->created_at->diffForHumans(),
//                        "yesNoMaybe" => [
//                            "Yes" => ["Yes","(Edit)"],
//                            "Ignore" => ["Not yet","(Ask later)"],
//                            "No" => ["Lost it","(Archive)"],
//                        ],
//                    ];
//                }

                /*
                 * Is the tentative materials date still correct?
                 */
//                if($notification->type === "App\Notifications\ProjectTentativeDateCheckEmail"){
//                    $project_name = $notification->data["project_name"] ?? null;
//                    $tentative_date = $notification->data["date_materials_required"] ?? null;
//
//                    $notifications[] = [
//                        "id" => $notification->id,
//                        "message" => "Is the tentative materials date of ".$tentative_date." for '".$project_name."' still correct",
//                        "timestamp" => $notification->created_at->diffForHumans(),
//                        "yesNoMaybe" => [
//                            "yes" => ["Yes","(Edit)"],
//                            "ignore" => ["Not yet","(Ask later)"],
//                            "no" => ["Lost it","(Archive)"],
//                        ],
//                    ];
//                }

                /**
                 * Quotes
                 */
                /*
                 * Quote due
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
        $directory = app_path('Services/Interfaces');
        return collect(File::files($directory))
            ->map(function ($file) {
                return $file->getFilename();
            })
            ->reject(function ($filename) {
                return $filename === 'NotificationInterface.php';
            })
            ->map(function ($filename) {
                return pathinfo($filename, PATHINFO_FILENAME);
            })
            ->values()
            ->toArray();
    }
}
