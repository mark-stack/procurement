<?php

namespace App\Http\Controllers;

use App\Http\Resources\ProjectResource;
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
        $nestingService = new NestingService();

        //Prerequisite variables
        $user = auth()->user();
        $business = $user->business;

        //Projects in batch
        $projectsInBatch = $batch->projects();

        //Pieces ready for batching
        $piecesInBatch = $batch->pieces;

        //Pieces nested
        $piecesNested = $nestingService->piecesNested($piecesInBatch);

        //Nesting stats
        $usage = $nestingService->usage($piecesNested);

        //Grouped by nesting algorithm
        $batchGroups = $nestingService->batchGroups($piecesNested);

        return Inertia::render('QuoteIndex',[
            "pieces" => $piecesNested,
            "projectsReadyForBatching" => ProjectResource::collection($projectsInBatch),
            "batchGroups" => $batchGroups,
            "usage" => $usage,
            "type" => "BATCH",
        ]);
    }
}
