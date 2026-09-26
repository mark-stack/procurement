<?php

namespace App\Http\Controllers;

use App\Services\NotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

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

        /*
         * Read off the caller's own notifications. A bare findOrFail took any notification id
         * in the table, and the red action on one of them archives the project it names - so
         * an id was all it took to archive another business's project.
         */
        $notification = $request->user()->notifications()->findOrFail($validated['id']);

        //No implementation claims every notification type, and the return type is not nullable
        $return = back();

        $implementations = (new NotificationService)->getImplementations();
        foreach ($implementations as $implementation) {
            $className = 'App\\Services\\NotificationImplementations\\'.$implementation;

            // Check if the class exists
            if (class_exists($className)) {
                $service = new $className;

                $trafficLight = $service->trafficLight($notification, $validated['status']);
                if ($trafficLight) {
                    $return = $trafficLight;
                }
            }
        }

        return $return;
    }
}
