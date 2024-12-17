<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\Interfaces\NotificationNewColleagueImplementation;
use App\Services\Interfaces\NotificationProjectAwardedImplementation;
use App\Services\NotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

class MarkNotificationStatusController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request): RedirectResponse
    {
        /**
         * Single purpose: action on notifications
         */
        $validated = $request->validate([
            'id' => 'required',
            'status' => 'required',
        ]);

        $notification = DatabaseNotification::findOrFail($validated["id"]); // Replace with the actual notification ID
        $return = back();

        $implementations = (new NotificationService())->getImplementations();
        foreach($implementations as $implementation){
            $className = 'App\\Services\\Interfaces\\'.$implementation;

            // Check if the class exists
            if (class_exists($className)) {
                $service = new $className();

                $trafficLight = $service->trafficLight($notification,$validated["status"]);
                if($trafficLight){
                    $return = $trafficLight;
                }
            }
        }

        return $return;
    }
}
