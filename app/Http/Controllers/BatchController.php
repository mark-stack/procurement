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
         * 1) Not if used for order
         */

        //1) Not if used for order
        if(!$batch->order){
            //Detach quotes
            foreach($batch->quotes as $quote){
                $quote->batch_id = null;
                $quote->save();
            }

            //Detach batch reference from pieces
            $batch->pieces()->update([
                "batch_id" => null,
            ]);

            //Delete batch
            $batch->delete();
        }

        return back();
    }
}
