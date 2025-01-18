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

        //Projects ready for batching
        $projectsReadyForBatching = $business->projectsReadyForBatching();

        //Pieces ready for batching
        $piecesReadyForBatching = $nestingService->piecesReadyForBatching($business);

        //Letter-project array
        $lettersProjectArray = $nestingService->getLetterProjectArray($piecesReadyForBatching);

        //Pieces nested
        $piecesNested = $nestingService->piecesNested($piecesReadyForBatching,$lettersProjectArray);

        //Nesting stats
        $usage = $nestingService->usage($piecesNested);

        //Grouped by nesting algorithm
        $batchGroups = $nestingService->batchGroups($piecesNested);

        return Inertia::render('QuoteIndex',[
            "pieces" => $piecesNested,
            "projectsReadyForBatching" => ProjectResource::collection($projectsReadyForBatching),
            "batchGroups" => $batchGroups,
            "usage" => $usage,
            "type" => "SUGGESTED",
        ]);
    }
}
