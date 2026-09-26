<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Notifications\WelcomeActivatedUserEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;

class ActivateBusinessController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, Business $business): RedirectResponse
    {
        /*
         * Activation is the one thing here that is visible outside the platform: it welcomes
         * every user in the business by email. Nothing stopped it running twice, so a
         * refresh, a double click or the back button re-welcomed all of them.
         */
        if ($business->admin_setup_complete) {
            return back()->with('warning', 'That business is already active.');
        }

        //Update business record
        $business->admin_setup_complete = true;
        $business->save();

        //Send confirmation email
        foreach ($business->users as $user) {
            Notification::send($user, new WelcomeActivatedUserEmail($user));
        }

        return back()->with('success', 'Business activated.');
    }
}
