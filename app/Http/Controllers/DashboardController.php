<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $user = auth()->user();
        $business = $user->business;

        //Onboarding complete
        if ($business->admin_setup_complete) {
            return redirect()->route('projects.index');
        }
        //Not onboarded yet
        else {
            return redirect()->route('onboarding');
        }
    }
}
