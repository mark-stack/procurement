<?php

namespace App\Http\Controllers;

use App\Actions\Piece\DetachPiecesFromBatch;
use App\Models\Batch;
use App\Models\Offcut;
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
        dd($request->all());
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
         * Break batch
         *
         * 1) All projects belong to your business
         * 2) At least 1 project is yours
         * 3) All projects not archived
         * 4) All materials are assigned to this batch
         * 5) No order sent
         * 6) Not your offcuts
         * 7) All offcuts 'allocate_to' = this batch
         */

        Gate::authorize('owned', $batch);

        $user = auth()->user();
        $business = $user->business;

//        $batchOrderedOrders = $batch->orders()
//            ->where('order_sent', true)
//            ->get();

        $offcutsAssignedToThisBatch = Offcut::query()
            ->where("batch_to_id",$batch->id)
            ->get();

        //1) All projects belong to your business
        $condition_1 = true;
        foreach($batch->projects() as $project){
            if($project->user->business->id !== $business->id){
                $condition_1 = false;
            }
        }

        //2) At least 1 project is yours
        $condition_2 = false;
        foreach($batch->projects() as $project){
            if($project->user->id === $user->id){
                $condition_2 = true;
            }
        }

        //3) All projects not archived
        $condition_3 = true;
        foreach($batch->projects() as $project){
            if($project->archive){
                $condition_3 = false;
            }
        }

        //4) All materials are assigned to this batch
        $condition_4 = true;
        foreach($batch->pieces as $piece){
            if($piece->batch->id !== $batch->id){
                $condition_4 = false;
            }
        }

        //5) No order sent
        $condition_5 = $batch->orders()->where("order_sent")->count() === 0;

        //6) Not your offcuts
        $condition_6 = true;
        foreach($offcutsAssignedToThisBatch as $offcut){
            $businessOwnership = $offcut->batchTo()->user->business->id === $business->id;
            if(!$businessOwnership){
                $condition_6 = false;
            }
        }

        //7) All offcuts 'allocate_to' = this batch
        $condition_7 = true;
        foreach($offcutsAssignedToThisBatch as $offcut){
            $batchOwnership = $offcut->batchTo()->id === $batch->id;
            if(!$batchOwnership){
                $condition_7 = false;
            }
        }

        $proceed =
            $condition_1 &&
            $condition_2 &&
            $condition_3 &&
            $condition_4 &&
            $condition_5 &&
            $condition_6 &&
            $condition_7;

        abort_if(!$proceed,403,"conditions not satisfied");

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
