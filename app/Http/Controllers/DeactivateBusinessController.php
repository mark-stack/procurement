<?php

namespace App\Http\Controllers;

use App\Models\Business;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DeactivateBusinessController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, Business $business): RedirectResponse
    {
        /*
         * The counterpart to activation, and deliberately not its mirror in one respect: this
         * emails nobody. There is no "your account has been switched off" message worth
         * sending, and activating afterwards welcomes everyone again anyway.
         *
         * Conditional update for the same reason activation uses one - the column is the
         * whole of the decision, so the database is what decides who changed it.
         */
        $deactivated = Business::query()
            ->whereKey($business->getKey())
            ->where('admin_setup_complete', true)
            ->update(['admin_setup_complete' => false]);

        if ($deactivated === 0) {
            return back()->with('warning', 'That business is not active.');
        }

        /*
         * What this costs them, said in the message rather than left to be discovered:
         * BusinessReadyMiddleware sends every route that matters back to onboarding.
         */
        return back()->with('success', 'Business deactivated. Everyone in it is back on onboarding until it is activated again.');
    }
}
