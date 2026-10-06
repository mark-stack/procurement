<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LandingController extends Controller
{
    /*
     * The landing page used to run a nest to draw itself.
     *
     * It looked up the SAMPLE business and called NestingFormatter::nestingViewData() on every hit, so
     * that the "Example nesting 200PFC across 2 projects" section could show the real algorithm
     * working. That section has gone; the same example is NestingExampleController's whole job, at
     * /try-nesting, where it is drawn for somebody who asked to see it rather than for everybody who
     * found the front page. A thousand-combination search is not page furniture.
     */
    public function __invoke(Request $request): Response
    {
        return Inertia::render('Welcome', [
            /*
             * The trial length the app will actually grant, rather than a figure typed into the
             * page. These have to agree: the button here is a promise, and App\Models\Business is
             * what keeps it.
             */
            "trialDays" => (int) config('billing.trial_days'),
        ]);
    }
}
