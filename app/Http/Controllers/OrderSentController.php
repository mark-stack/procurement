<?php

namespace App\Http\Controllers;

use App\Actions\Order\SetOrderSentForBatchSupplierGroup;
use App\Actions\OrderApproval\UpdateOrderApprovalStatus;
use App\Actions\Piece\AttachPiecesToOrder;
use App\Models\Batch;
use App\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class OrderSentController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, Batch $batch): RedirectResponse
    {
        /**
         * Update or create quote & order based on BATCH and SUPPLIER_CATEGORY
         * Action 1: SetOrderSentForBatchSupplierGroup
         *   Action 1A: UpdateOrderSentStatus
         * Action 2: AttachPiecesToOrder
         * Action 3: UpdateOrderApprovalsForBatch
         */

        Gate::authorize('owned', $batch);

        $validated = $request->validate([
            'order_id' => ['required'],
        ]);

        $orderedOrder = Order::findOrFail($validated['order_id']);

        //Only 1 order in the batch supplier group can be TRUE
        SetOrderSentForBatchSupplierGroup::run($orderedOrder, $batch);

        //Attach pieces to order
        AttachPiecesToOrder::run($batch, $orderedOrder);

        //All project managers approve ordering materials
        UpdateOrderApprovalStatus::run($batch, true);

        return back();
    }
}
