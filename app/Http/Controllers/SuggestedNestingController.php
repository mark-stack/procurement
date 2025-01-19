<?php

namespace App\Http\Controllers;

use App\Http\Resources\ProjectResource;
use App\Services\NestingService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SuggestedNestingController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request): Response
    {
        //Services
        $nestingService = new NestingService();

        //Prerequisite variables
        $user = auth()->user();
        $business = $user->business;

        //View data
        $batchData = $nestingService->getBatchDataForView("SUGGESTED",$business,null);

        return Inertia::render('QuoteIndex',$batchData);
    }
}
