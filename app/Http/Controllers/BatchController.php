<?php

namespace App\Http\Controllers;

use App\Actions\Piece\DetachPiecesFromBatch;
use App\Models\Batch;
use App\Models\Offcut;
use App\PrerequisiteConditions\PrerequisiteConditions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class BatchController extends Controller
{
    public function index()
    {
        //
    }

    public function create()
    {
        //
    }

    public function store(Request $request)
    {
        //Batches are created by QuoteController::store when a user starts quoting
        abort(404);
    }

    public function show(Batch $batch)
    {
        Gate::authorize('owned', $batch);
    }

    public function edit(Batch $batch)
    {
        Gate::authorize('owned', $batch);
    }

    public function update(Request $request, Batch $batch)
    {
        Gate::authorize('owned', $batch);
    }

    public function destroy(Batch $batch): RedirectResponse
    {
        /**
         * Break batch to renest
         *
         */

        Gate::authorize('owned', $batch);

        $user = auth()->user();
        $business = $user->business;

        $offcutsAssignedToThisBatch = Offcut::query()
            ->where("batch_to_id",$batch->id)
            ->get();

        //Prerequisite conditions
        $prerequisiteUndoStartQuoting = (new PrerequisiteConditions())->undoStartQuoting(
            $batch,
            $user,
            $offcutsAssignedToThisBatch,
        );
        abort_if(!$prerequisiteUndoStartQuoting,403);

        //Delete orders
        $batch->orders()->delete();

        //Detach pieces from quote
        foreach ($batch->pieces as $piece) {
            $piece->quotes()->detach();
        }

        //Delete quotes
        $batch->quotes()->delete();

        //Detach pieces from batch
        DetachPiecesFromBatch::run($batch);

        //Delete order approvals
        $batch->orderApprovals()->delete();

        //Un-associate any offcuts ("batch_to_id")
        foreach($offcutsAssignedToThisBatch as $offcut){
            $offcut->batch_to_id = null;
            $offcut->save();
        }

        //Delete batch
        $batch->delete();

        return back();
    }
}
