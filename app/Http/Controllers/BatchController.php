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
         * 1) Not if used for order (order_sent = false)
         */

        Gate::authorize('owned', $batch);

        $batchOrderedOrders = $batch->orders()
            ->where('order_sent', true)
            ->get();

        //1) Not if used for order
        if ($batchOrderedOrders->count() === 0) {
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
            Offcut::query()
                ->where("batch_to_id",$batch->id)
                ->update([
                    "batch_to_id" => null
                ]);

            //Delete batch
            $batch->delete();
        }

        return back();
    }
}
