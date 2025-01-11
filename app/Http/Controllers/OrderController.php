<?php

namespace App\Http\Controllers;

use App\Models\Batch;
use App\Models\Order;
use App\Models\Piece;
use App\Models\Quote;
use App\Services\NestingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
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
    public function store(Request $request): RedirectResponse
    {
        /**
         * 1) Case #1: Batch exists - create & attach order to batch
         * 2) Case #2: Batch required - create & attach order to batch
         */
        //Validate
        $validated = $request->validate([
            "batch_id" => 'nullable',
        ]);

        /*
         * Prerequisite variables
         */
        $user = auth()->user();
        $business = $user->business;
        $batchId = $validated["batch_id"];

        /*
         * Case #1: Batch exists - create & attach order to batch
         */
        if($batchId){
            //Get batch
            $batch = Batch::findOrFail($batchId);

            //Get quote (if exists)
            $quote = $batch->quote;

            //Create order and attach to batch
            $order = Order::create([
                'user_id' => $user->id,
                'batch_id' => $batch->id,
                'supplier_id' => null,
                'quote_id' => $quote ? $quote->id : null,
            ]);
        }

        /*
         * 2) Case #2: Batch required - create & attach order to batch
         */
        else{
            /*
             * Services
             */
            $nestingService = new NestingService();

            /*
             * Create batch
             */
            $batch = Batch::create([
                'user_id' => $user->id,
                "total_length" => 999, //todo
                "total_used_length" => 999, //todo
            ]);

            //Get quote (if exists)
            $quote = $batch->quote;

            /*
             * Assign all PIECE objects to BATCH
             */
            $piecesReadyForBatching = $nestingService->piecesReadyForBatching($business);
            Piece::query()
                ->whereIn("id",$piecesReadyForBatching->pluck("id"))
                ->update([
                    "batch_id" => $batch->id,
                ]);

            //Create order and attach to batch
            $order = Order::create([
                'user_id' => $user->id,
                'batch_id' => $batch->id,
                'supplier_id' => null,
                'quote_id' => $quote ? $quote->id : null,
            ]);
        }

        return back();
    }

    /**
     * Display the specified resource.
     */
    public function show(Order $order)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Order $order)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Order $order)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Order $order): RedirectResponse
    {
        /**
         * 1) No order has been made
         */

        if(!$order->order_sent){
            //Create quote if doesn't exist
            $quote = Quote::firstOrCreate(
                [
                    "batch_id" => $order->batch_id,
                ],
                [
                    'user_id' => $order->user_id,
                    "quote_requests" => null,
                    "quote_responses" => null,
                ]
            );

            //Detach order from batch
            $order->batch_id = null;
            $order->save();
        }

        return back();
    }
}
