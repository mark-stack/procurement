<?php

namespace App\Http\Controllers;

use App\Http\Resources\ProjectResource;
use App\Models\Piece;
use App\Models\Project;
use App\Models\Quote;
use App\Services\NestingService;
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
        $user = auth()->user();
        $business = $user->business;
        $allStaffIds = $business->users()->pluck("id")->toArray();

        //todo: timeline and status criteria needed
        $projectsForQuoting = Project::query()
            ->active()
            ->awarded()
            ->whereIn("user_id",$allStaffIds)
            ->with("user")
            ->has("pieces")
            ->get();

        $projectsForQuotingIds = $projectsForQuoting
            ->pluck("id")
            ->toArray();

        $pieces = Piece::query()
            ->whereIn("project_id",$projectsForQuotingIds)
            ->get();

        $nestingService = new NestingService();

        $piecesClassifiedByNestingAlgorithm = $nestingService->piecesClassifiedByNestingAlgorithm($pieces);

        $piecesNested = [];
        foreach($piecesClassifiedByNestingAlgorithm as $nestingAlgoLabel => $pieces){
            $piecesNested[] = $nestingService->nesting($nestingAlgoLabel,$pieces);
        }


        /**
         * Sums of material totals, usage, and waste
         */
        $totalMaterial = 0;
        $totalUsedMaterial = 0;
        $totalWaste = 0;
        foreach($piecesNested as $items){
            foreach($items as $item){
                if(isset($item["nested"]["totals"])){
                    $totals = $item["nested"]["totals"];

                    $totalMaterial = $totalMaterial + $totals["totalMaterial"];
                    $totalUsedMaterial = $totalUsedMaterial + $totals["totalUsedMaterial"];
                    $totalWaste = $totalWaste + $totals["totalWaste"];
                }
            }
        }
        $usage = [
            "totalMaterial" => $totalMaterial,
            "totalUsedMaterial" => $totalUsedMaterial,
            "totalWaste" => $totalWaste,
        ];

        /**
         * Batch groups
         */
        $batchGroups = $nestingService->batchGroups($piecesNested);

        return Inertia::render('QuoteIndex',[
            "pieces" => $piecesNested,
            "projectsForQuoting" => ProjectResource::collection($projectsForQuoting),
            "batchGroups" => $batchGroups,
            "usage" => $usage,
        ]);
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
    public function store(Request $request)
    {
        //
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
