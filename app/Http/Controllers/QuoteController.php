<?php

namespace App\Http\Controllers;

use App\Http\Resources\ProjectResource;
use App\Models\Batch;
use App\Models\Order;
use App\Models\Piece;
use App\Models\Project;
use App\Models\Quote;
use App\Services\NestingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class QuoteController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): Response
    {
//        //Services
//        $nestingService = new NestingService();
//
//        $user = auth()->user();
//        $business = $user->business;
//
//
//        $projectsReadyForBatching = $business->projectsReadyForBatching();
//
//        $piecesReadyForBatching = $nestingService->piecesReadyForBatching($business);
//
//        /*
//         * Nested by algorithm
//         */
//        $piecesNested = $nestingService->piecesNested($piecesReadyForBatching);
//
//
//
//        /**
//         * Sums of material totals, usage, and waste
//         */
//        $totalMaterial = 0;
//        $totalUsedMaterial = 0;
//        $totalWaste = 0;
//
//        foreach($piecesNested as $items){
//            foreach($items as $item){
//                $item = (array) $item;
//                if(isset($item["nested"]["totals"])){
//                    $totals = $item["nested"]["totals"];
//
//                    $totalMaterial = $totalMaterial + $totals["totalMaterial"];
//                    $totalUsedMaterial = $totalUsedMaterial + $totals["totalUsedMaterial"];
//                    $totalWaste = $totalWaste + $totals["totalWaste"];
//                }
//            }
//        }
//        $usage = [
//            "totalMaterial" => $totalMaterial,
//            "totalUsedMaterial" => $totalUsedMaterial,
//            "totalWaste" => $totalWaste,
//        ];
//
//        /**
//         * Batch groups
//         */
//        $batchGroups = $nestingService->batchGroups($piecesNested);
//
//        return Inertia::render('QuoteIndex',[
//            "pieces" => $piecesNested,
//            "projectsReadyForBatching" => ProjectResource::collection($projectsReadyForBatching),
//            "batchGroups" => $batchGroups,
//            "usage" => $usage,
//        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            //
        ]);

        /*
         * Services
         */
        $nestingService = new NestingService();

        /*
         * Prerequisite variables
         */
        $user = auth()->user();
        $business = $user->business;

        /*
         * Create batch
         */
        $batch = Batch::create([
            'user_id' => $user->id,
            "total_length" => 999, //todo
            "total_used_length" => 999, //todo
        ]);

        /*
         * Assign all PIECE objects to BATCH
         */
        $piecesReadyForBatching = $nestingService->piecesReadyForBatching($business);
        Piece::query()
            ->whereIn("id",$piecesReadyForBatching->pluck("id"))
            ->update([
                "batch_id" => $batch->id,
            ]);

        /*
         * Create order & attach batch
         */
        $quote = Quote::Create([
            'user_id' => $user->id,
            "batch_id" => $batch->id,
        ]);

        return back();
    }

    /**
     * Display the specified resource.
     */
    public function show(Quote $quote)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Quote $quote)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Quote $quote)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Quote $quote)
    {
        //
    }
}
