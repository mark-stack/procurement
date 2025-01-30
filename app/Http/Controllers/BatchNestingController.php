<?php

namespace App\Http\Controllers;

use App\Formatters\NestingFormatter;
use App\Models\Batch;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BatchNestingController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, Batch $batch): Response
    {
        //Formatter
        $nestingFormatter = new NestingFormatter();

        //Prerequisite variables
        $user = auth()->user();
        $business = $user->business;

        //View data
        $viewData = $nestingFormatter->nestingViewData('BATCH', $business, $batch);

        $viewData = array_merge($viewData, [
            'width' => 900,
        ]);

        return Inertia::render('QuoteIndex', $viewData);
    }
}
