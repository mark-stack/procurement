<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\NotificationLog;
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
         * A business that can import nothing has not been set up, whatever the column says.
         *
         * Its uploads are matched against its own templates and nothing else, so with none
         * recorded the welcome email says the setup configuration is complete and the first
         * spreadsheet they try answers "didn't auto-detect properly, did the template change?" -
         * blaming the customer's file for work that was never done. detectableTemplates(), not
         * templates(): a deactivated row, or one recorded before templates carried the heading
         * cell that finds the table, detects nothing either. Same count the admin users list
         * shows in red beside this button.
         *
         * Suppliers are deliberately not checked. A business with no suppliers can still import
         * and nest - it just cannot quote yet - and the customer can add their own on
         * /suppliers. Templates are the only thing that only we can do.
         */
        if ($business->detectableTemplates()->doesntExist()) {
            return back()->with('warning', 'That business has no templates that can detect a table, so it could not import anything. Record one first, then activate.');
        }

        /*
         * Activation is the one thing here that is visible outside the platform: it welcomes
         * every user in the business by email, and that cannot be taken back.
         *
         * In a transaction with the welcome, so that a business left reading "Active" with
         * nobody welcomed is not a state this can end in: the refusal below would then turn
         * away every retry. The queue is this same database, so the jobs commit with the
         * column and no worker can pick one up before the row it describes exists.
         */
        //Resolved before the transaction so the decision is made once, and read twice below
        $trial = $this->trialFrom($business);

        $activated = DB::transaction(function () use ($business, $trial) {
            /*
             * The database decides whether this is the activation, rather than a read here
             * followed by a write. Both were separate statements, so two requests in flight
             * together - a double click is the ordinary case - each read the column as false
             * and each welcomed every user in the business.
             */
            $claimed = Business::query()
                ->whereKey($business->getKey())
                ->where('admin_setup_complete', false)
                ->update(['admin_setup_complete' => true, ...$trial]);

            if ($claimed === 0) {
                return false;
            }

            /*
             * A restarted trial gets its warnings back.
             *
             * SendTrialReminders records one mark per business per bucket in notification_logs and
             * never sends the same mark twice, which is what stops the hourly schedule mailing
             * daily. A business whose trial ran down before it was ever activated therefore
             * carries TRIAL_ENDED already, and without this would go through the whole of its real
             * trial with no seven-day warning and no notice at the end of it. That job no longer
             * writes marks for an inactive business at all, so this is for the rows written before
             * it stopped - and for the deactivate/activate loop, which restarts the trial too.
             *
             * Only when the date actually moved. An account that has paid, or one already carrying
             * a longer trial than the config default, keeps its history.
             *
             * Scoped by the type prefix because unique_model_id means a business here and a
             * project for most other marks.
             */
            if ($trial !== []) {
                NotificationLog::query()
                    ->where('unique_model_id', $business->getKey())
                    ->where('type', 'like', 'TRIAL\_%')
                    ->delete();
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

    /**
     * The trial runs from here, because this is the first minute the product can be used.
     *
     * Business::booted() starts it when the row is created, which is at registration - and
     * registration is followed by the onboarding page asking the customer to email us their
     * spreadsheets, which we take up to two business days to turn into templates. So two days of
     * a thirty-day trial were always spent before anybody could import a thing, and a signup that
     * sat unactivated for a month arrived read-only in its first minute: everything readable,
     * every save refused, over a trial they never got to use.
     *
     * Only ever pushed outwards. A business activated the same day it registered keeps the date
     * it already has rather than gaining hours, and a fixture or a seeder that deliberately set a
     * long trial is not shortened to the config default.
     *
     * Nothing here touches an account that has paid. A manual grant or a live subscription
     * outranks the trial in App\Billing\Billing, so the date would be ignored anyway - but it
     * would come back into play if they later cancelled, which is a free month nobody agreed to.
     *
     * @return array<string, \Illuminate\Support\Carbon>
     */
    private function trialFrom(Business $business): array
    {
        if ($business->billingState()->everPaid()) {
            return [];
        }

        $fresh = now()->addDays((int) config('billing.trial_days'));

        if ($business->trial_ends_at !== null && $business->trial_ends_at->greaterThan($fresh)) {
            return [];
        }

        return ['trial_ends_at' => $fresh];
    }
}
