<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Notifications\WelcomeActivatedUserEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
         * every user in the business by email, and that cannot be taken back.
         *
         * In a transaction with the welcome, so that a business left reading "Active" with
         * nobody welcomed is not a state this can end in: the refusal below would then turn
         * away every retry. The queue is this same database, so the jobs commit with the
         * column and no worker can pick one up before the row it describes exists.
         */
        $activated = DB::transaction(function () use ($business) {
            /*
             * The database decides whether this is the activation, rather than a read here
             * followed by a write. Both were separate statements, so two requests in flight
             * together - a double click is the ordinary case - each read the column as false
             * and each welcomed every user in the business.
             */
            $claimed = Business::query()
                ->whereKey($business->getKey())
                ->where('admin_setup_complete', false)
                ->update(['admin_setup_complete' => true]);

            if ($claimed === 0) {
                return false;
            }

            //One send, not one per user: the notification reads its recipient off the
            //notifiable, so the collection form addresses each of them correctly
            Notification::send($business->users, new WelcomeActivatedUserEmail);

            return true;
        });

        if (! $activated) {
            return back()->with('warning', 'That business is already active.');
        }

        return back()->with('success', 'Business activated.');
    }
}
