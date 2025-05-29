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

        $sampleData = $nestingFormatter->nestingViewData('SUGGESTED', $sampleBusiness, null);

        return Inertia::render('TryNesting', [
            "sampleNestingData" => $sampleData
        ]);
    }
}
