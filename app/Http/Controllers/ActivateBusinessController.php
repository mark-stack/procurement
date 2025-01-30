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
        //Update business record
        $business->admin_setup_complete = true;
        $business->save();

        //Send confirmation email
        foreach ($business->users as $user) {
            Notification::send($user, new WelcomeActivatedUserEmail($user));
        }

        return back();
    }
}
