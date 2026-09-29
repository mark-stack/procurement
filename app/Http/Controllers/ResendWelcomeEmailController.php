<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Notifications\WelcomeActivatedUserEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ResendWelcomeEmailController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, User $user): RedirectResponse
    {
        /*
         * Per user, not per business. Activation welcomes everyone at once and can only happen
         * once, so the thing an admin actually needs afterwards is to put right the one person
         * whose email bounced, went to spam, or was queued while the worker was down - not to
         * mail the whole business again.
         */
        $business = $user->business;

        if (! $business) {
            return back()->with('warning', 'That user has no business, so there is nothing to welcome them to.');
        }

        /*
         * The email says the setup configuration is complete, and its link lands on projects,
         * which BusinessReadyMiddleware guards. Sending it before activation would be a lie
         * followed by a redirect back to onboarding.
         */
        if (! $business->admin_setup_complete) {
            return back()->with('warning', 'That business is not active yet. Activate it, which welcomes everyone in it.');
        }

        /*
         * Each send mints another magic link, and the earlier ones stay valid for their 72
         * hours rather than being revoked. They all sign the same person into the same
         * account from the same inbox, so that is a duplicate rather than a second way in.
         */
        $user->notify(new WelcomeActivatedUserEmail);

        return back()->with('success', 'Welcome email resent to '.$user->email.'.');
    }
}
