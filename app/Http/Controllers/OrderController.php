<?php

namespace App\Http\Controllers;

use App\Models\Batch;
use App\Models\Order;
use App\Models\OrderApproval;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

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
            'batch_id' => ['nullable', 'integer'],
        ]);

        /*
         * Prerequisite variables
         */
        $user = auth()->user();
        $business = $user->business;

        //"nullable" means validated() has no key at all when the field is missing
        $batchId = $validated['batch_id'] ?? null;

        abort_if(! $batchId, 401);

        /*
         * Case #1: Batch exists - create & attach order to batch
         */

        //Get batch
        $batch = Batch::findOrFail($batchId);

        //Nothing else here scoped the batch to the caller, so any batch id could be ordered against
        Gate::authorize('owned', $batch);

        //Get quote (if exists)
        $quotes = $batch->quotes;

        //Create pending orders (1:1 with quotes) and attach to batch
        foreach ($quotes as $quote) {
            Order::firstOrCreate(
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
        foreach ($projectsReadyForBatching as $project) {
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
        Gate::authorize('owned', $order);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Order $order)
    {
        Gate::authorize('owned', $order);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Order $order): RedirectResponse
    {
        Gate::authorize('owned', $order);

        $validated = $request->validate([
            'purchase_order_number' => ['nullable'],
            "material_cert_numbers" => ['nullable'],
        ]);

        $order->update($validated);

        return back();
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Order $order): RedirectResponse
    {
        Gate::authorize('owned', $order);

        /**
         * 1) No order has been made
         */
        if (! $order->order_sent) {
            /*
             * This used to also firstOrCreate a Quote with quote_requests / quote_responses. Neither
             * column exists on the quotes table, so with $guarded = [] the insert always failed - and a
             * quote with no supplier or category is not something detaching an order should mint anyway.
             */

            //Detach order from batch
            $order->batch_id = null;
            $order->save();
        }

        return back();
    }
}
