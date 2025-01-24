<?php

namespace App\Http\Controllers;

use App\Models\Batch;
use App\Models\Order;
use App\Models\OrderApproval;
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
         * 2) Case #2: Batch required - create & attach order to batch (probably the "order now" button)
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

        abort_if(!$batchId,401);

        /*
         * Case #1: Batch exists - create & attach order to batch
         */

        //Get batch
        $batch = Batch::findOrFail($batchId);

        //Get quote (if exists)
        $quotes = $batch->quotes;

        //Create pending orders (1:1 with quotes) and attach to batch
        foreach($quotes as $quote){
            $order = Order::firstOrCreate(
                [
                    'batch_id' => $batch->id,
                    'quote_id' => $quote->id,
                ],
                [
                    'user_id' => $user->getKey(),
                    'supplier_id' => null,
                ]
            );
        }

        /*
         * Create pending order approvals
         */
        $projectsReadyForBatching = $business->projectsReadyForBatching();
        foreach($projectsReadyForBatching as $project){
            OrderApproval::firstOrCreate(
                [
                    'batch_id' => $batch->id,
                    'project_id' => $project->id,
                ],
                [
                    'project_manager_approved' => false,
                ]
            );
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
