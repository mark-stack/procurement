<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class AdminStopImpersonationController extends Controller
{
    /**
     * Handle the incoming request.
     *
     * This cannot sit behind AdminMiddleware: while impersonating, isAdmin() reads the
     * impersonated user's email, so the middleware would bounce the one request that
     * ends the impersonation.
     */
    public function __invoke(Request $request): RedirectResponse
    {
        $adminId = $request->session()->get('impersonator_id');

        //Not impersonating - a stale tab, or a double submit that already returned
        if (! $adminId) {
            return redirect()->route('dashboard');
        }

        $admin = User::find($adminId);

        /*
         * The admin row can be deleted while impersonating. There is no identity left to
         * return to, so end the session rather than leave it stuck as the impersonated user.
         */
        if (! $admin) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect('/');
        }

        Log::info('Admin impersonation stopped', [
            'admin_id' => $admin->id,
            'admin_email' => $admin->email,
            'user_id' => $request->user()?->id,
            'user_email' => $request->user()?->email,
        ]);

        //Clears impersonator_id along with anything the impersonated session accumulated
        $request->session()->invalidate();

        Auth::login($admin);

        return redirect()->route('admin.users.index');
    }
}
