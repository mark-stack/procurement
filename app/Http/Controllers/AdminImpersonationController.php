<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class AdminImpersonationController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, User $user): RedirectResponse
    {
        $admin = $request->user();

        /*
         * isAdmin() is a single-email comparison, so the admin's own row is the only one
         * this can match today. It is still checked here rather than only in the template:
         * impersonating yourself would overwrite impersonator_id with your own id and make
         * the way back a no-op.
         */
        if ($user->isAdmin()) {
            return back()->with('warning', 'An admin account cannot be impersonated.');
        }

        /*
         * Written before the swap. Once Auth::login() runs there is nothing left in the
         * request that says who was really driving, and everything created from here on
         * (quotes, orders, supplier emails) is stored against the impersonated user.
         */
        Log::info('Admin impersonation started', [
            'admin_id' => $admin->id,
            'admin_email' => $admin->email,
            'user_id' => $user->id,
            'user_email' => $user->email,
        ]);

        /*
         * Auth::login() rotates the session id but keeps the payload, so the admin's flash
         * messages and intended url would otherwise carry into the impersonated session.
         */
        $adminId = $admin->id;
        $request->session()->invalidate();

        Auth::login($user);

        //The only record of who to return to, so it has to outlive the login above
        $request->session()->put('impersonator_id', $adminId);

        /*
         * dashboard sits behind 'verified' and unverified users are exactly the ones an
         * admin opens this page to look at, so sending them to the dashboard lands them on
         * the verification prompt with no explanation. Go there deliberately instead - that
         * page carries the banner with the way back.
         */
        return redirect()->route(
            $user->hasVerifiedEmail() ? 'dashboard' : 'verification.notice'
        );
    }
}
