<?php

namespace App\Http\Controllers;

use App\Models\Batch;
use App\Services\NestingService;
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
        //Services
        $nestingService = new NestingService;

        //Prerequisite variables
        $user = auth()->user();
        $business = $user->business;

        //View data
        $batchData = $nestingService->getBatchDataForView('BATCH', $business, $batch);

        $viewData = array_merge($batchData, [
            'width' => 900,
        ]);

        //dd(1,$viewData);

        return Inertia::render('QuoteIndex', $viewData);
    }
}
