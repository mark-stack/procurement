<?php

namespace App\Http\Controllers;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PastProjectsController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request): Response
    {
        $business = auth()->user()->business;
        $pastBatches = $business->batches()
            ->inactive()
            ->latest()
            ->get();

        $pastBatchesWithExtraData = [];
        foreach($pastBatches as $pastBatch){
            $pastBatchesWithExtraData[] = [
                "id" => $pastBatch->id,
                "createdAt" => Carbon::parse($pastBatch->created_at)->diffForHumans(),
                "projectManager" => $pastBatch->user->name,
                "nestingData" => $pastBatch->nested_state, //todo refactor this to collecting 'BAR' and 'OFFCUT' items
                "projects" => $pastBatch->projects(),
                "ordersQty" => $pastBatch->orders()->count(),
            ];
        }

        return Inertia::render('PastProjectsIndex', [
            "pastBatches" => $pastBatchesWithExtraData,
        ]);
    }
}
