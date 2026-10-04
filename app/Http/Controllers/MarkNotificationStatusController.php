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
        /*
         * The three the bell can send. It was 'required' alone, so any other string fell through
         * every implementation's match() to its default arm - which for most of them is back(),
         * i.e. the click reported success and did nothing, leaving the notification in the bell.
         */
        $validated = $request->validate([
            'id' => 'required',
            'status' => ['required', 'in:GREEN,YELLOW,RED'],
        ]);

        /*
         * Read off the caller's own notifications. A bare findOrFail took any notification id
         * in the table, and the red action on one of them marks done the project it names - so
         * an id was all it took to mark another business's project done.
         */
        $notification = $request->user()->notifications()->findOrFail($validated['id']);

        //No implementation claims every notification type, and the return type is not nullable
        $return = back();

        foreach ((new NotificationService)->implementations() as $implementation) {
            $trafficLight = $implementation->trafficLight($notification, $validated['status']);

            if ($trafficLight) {
                //A row has exactly one type, so the first implementation to claim it is the one
                return $trafficLight;
            }
        }

        return $return;
    }
}
