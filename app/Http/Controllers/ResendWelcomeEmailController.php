<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Notifications\WelcomeActivatedUserEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ResendWelcomeEmailController extends Controller
{
    /**
     * Sends a user a magic link that signs them in and verifies their address.
     *
     * It used to be the follow-up to activation: an admin switched a business on, everybody in it was
     * welcomed, and this put right the one person whose email bounced. Activation is gone - a business
     * can import from its first upload - so what is left is the useful half, which is a way to get a
     * customer into their account when the verification email from registration never arrived or has
     * expired. The wording is unchanged because what it says is still true, and truer than it was:
     * their setup is complete, from the minute they signed up.
     */
    public function __invoke(Request $request, User $user): RedirectResponse
    {
        //Per user: the thing an admin needs is to get one person in, not to mail a whole business
        if (! $user->business) {
            return back()->with('warning', 'That user has no business, so there is nothing to welcome them to.');
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
