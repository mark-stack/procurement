<?php

namespace App\Http\Controllers;

use App\Actions\Bar\AttachBarsToOrder;
use App\Actions\Order\SetOrderSentForBatchSupplierGroup;
use App\Actions\OrderApproval\UpdateOrderApprovalStatus;
use App\Actions\Piece\AttachPiecesToOrder;
use App\Models\Batch;
use App\Models\Order;
use App\Services\NotificationImplementations\NotificationColleagueOrderedImplementation;
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
            'order_id' => ['required', 'integer'],
        ]);

        $orderedOrder = Order::findOrFail($validated['order_id']);

        /*
         * The gate above only covers the batch. order_id arrives from the request, so it needs its own
         * check - otherwise your own batch id plus somebody else's order id marked their order sent and
         * pointed your pieces at it.
         */
        Gate::authorize('owned', $orderedOrder);
        abort_if($orderedOrder->batch_id !== $batch->id, 404);

        /*
         * Somebody else in this supplier group has already had their steel delivered.
         *
         * Sending this one would un-send theirs - that is what the action does to keep one sent order
         * per group - and un-sending a delivered order is exactly what the undo route refuses
         * (OrderUndoSentController). Refused here too, with the reason, rather than leaving a row that
         * is delivered but not sent: the certificate trail reads order_sent and the offcut inventory
         * reads is_delivered, so the two would disagree about the same steel.
         */
        $delivered = SetOrderSentForBatchSupplierGroup::make()->deliveredSibling($orderedOrder, $batch);

        if ($delivered !== null) {
            return back()->withErrors([
                'order' => 'Another order for these materials has already been delivered, so this '
                    .'one cannot be marked as placed as well. The delivery would have to be undone '
                    .'first.',
            ]);
        }

        //Only 1 order in the batch supplier group can be TRUE
        SetOrderSentForBatchSupplierGroup::run($orderedOrder, $batch);

        //Attach pieces to order
        AttachPiecesToOrder::run($batch, $orderedOrder);

        /*
         * And the bars, which is the other half of the same fact.
         *
         * The pieces are what was asked for; the bars are what is being bought to make them. Only the
         * bars can carry a heat number, and only an order carries certificates - so this column is the
         * join between a cut and the steel it is made of. See App\Actions\Bar\AttachBarsToOrder.
         */
        AttachBarsToOrder::run($batch, $orderedOrder);

        /*
         * Ordering is approved for the batch, on behalf of every project manager on it, by the
         * person who pressed the button - recorded, because the flag alone said "they all agreed"
         * and nobody but this one user was ever asked.
         */
        UpdateOrderApprovalStatus::run($batch, true, $request->user());

        /*
         * And tell them, which recording it does not do. approved_by_user_id makes the truth
         * auditable afterwards; this is what puts it in front of the person whose approval was given.
         * Once per project per batch, so undoing and re-sending an order does not ring twice.
         */
        (new NotificationColleagueOrderedImplementation)->notifyAffectedProjectManagers($batch, $request->user());

        return back();
    }
}
