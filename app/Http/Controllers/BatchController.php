<?php

namespace App\Http\Controllers;

use App\Models\Batch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BatchController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
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
        dd($request->all());
    }

    /**
     * Display the specified resource.
     */
    public function show(Batch $batch)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Batch $batch)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Batch $batch)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Batch $batch): RedirectResponse
    {
        /**
         * Break batch - not fully delete it
         * 1) Not if used for order
         */

        //1) Not if used for order
        if(!$batch->order){
            //Detach quote
            $quote = $batch->quote;
            $quote->batch_id = null;
            $quote->save();

            //Detach batch reference from pieces
            $batch->pieces()->update([
                "batch_id" => null,
            ]);
        }

        return back();
    }
}
