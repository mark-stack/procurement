<?php

namespace App\Http\Controllers;

use App\Models\Batch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

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
        //
    }

    public function edit(Batch $batch)
    {
        //
    }

    public function update(Request $request, Batch $batch)
    {
        //
    }

    public function destroy(Batch $batch): RedirectResponse
    {
        /**
         * Break batch
         * 1) Not if used for order (order_sent = false)
         */

        $batchOrderedOrders = $batch->orders()
            ->where("order_sent",true)
            ->get();

        //1) Not if used for order
        if($batchOrderedOrders->count() === 0){
            //Delete orders
            $batch->orders()->delete();

            //Delete quotes
            $batch->quotes()->delete();

            //Detach batch reference from pieces
            $batch->pieces()->update([
                "batch_id" => null,
            ]);

            //Delete order approvals
            $batch->orderApprovals()->delete();

            //Delete batch
            $batch->delete();
        }

        return back();
    }
}
