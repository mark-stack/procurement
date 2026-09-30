<?php

namespace App\Http\Controllers;

use App\Formatters\NestingFormatter;
use App\Models\Business;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NestingExampleController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request): Response
    {
        //Formatter
        $nestingFormatter = new NestingFormatter();

        /*
         * Get sample nesting data
         */
        $sampleBusiness = Business::query()
            ->where('name',"SAMPLE")
            ->where('domain','sample.com')
            ->first();

        /*
         * The sample business comes from DatabaseSeeder, so it is a row that can simply not be there
         * - a database seeded before it was added, or one where somebody renamed it. nestingViewData
         * takes a Business and not a null, so this page answered a signed-out visitor with a 500 off
         * a TypeError, and this is the marketing page the landing page sends them to.
         *
         * Guarded the way LandingController already guards the identical lookup, and TryNesting.vue
         * already draws nothing when the prop is null - both halves of the fix were in place, on
         * either side of the one controller that skipped it.
         */
        $sampleData = $sampleBusiness
            ? $nestingFormatter->nestingViewData('SUGGESTED', $sampleBusiness, null)
            : null;

        return Inertia::render('TryNesting', [
            "sampleNestingData" => $sampleData
        ]);
    }
}
