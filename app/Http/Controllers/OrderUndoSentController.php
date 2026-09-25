<?php

namespace App\Http\Controllers;

use App\Actions\OrderApproval\UpdateOrderApprovalStatus;
use App\Actions\Piece\DetachPiecesFromOrder;
use App\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class OrderUndoSentController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, Order $order): RedirectResponse
    {
        /**
         * Undo order sent
         */
        Gate::authorize('owned', $order);

        /*
         * The page hides this control unless the order is sent and not yet delivered, but the route is
         * reachable directly - and undoing an order that was never sent would clear approvals that this
         * order never collected.
         */
        abort_if(! $order->order_sent, 403, 'Order has not been sent');
        abort_if($order->is_delivered, 403, 'Cannot undo a delivered order');

        //batch_id is nullable, and both actions below need a batch
        $batch = $order->batch;
        abort_if(! $batch, 404);

        $order->order_sent = false;
        $order->save();

        //Detach pieces to order
        DetachPiecesFromOrder::run($batch, $order);

        /*
         * Sending the order set every approval on the batch to true (OrderSentController), so undoing it
         * has to put them back - otherwise re-sending skips the project manager approval that the
         * confirm dialog exists to collect.
         */
        UpdateOrderApprovalStatus::run($batch, false);

        return back();
    }
}
