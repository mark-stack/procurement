<?php

namespace App\Http\Controllers;

use App\Actions\Bar\DetachBarsFromOrder;
use App\Actions\Order\UpdateOrderSentStatus;
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

        /*
         * Through the action rather than setting the flag here, so that order_sent_at is cleared with
         * it. Two columns describing one fact have to agree, and this was the second writer of one of
         * them - see UpdateOrderSentStatus.
         */
        UpdateOrderSentStatus::run($order, false);

        //Detach pieces to order
        DetachPiecesFromOrder::run($batch, $order);

        /*
         * And let go of the bars. They were attached when the order was placed (AttachBarsToOrder), so
         * an order that is no longer placed has not bought them - leaving them pointed at it would put
         * a heat number column on an order nobody has sent, and would mean two orders in the same
         * supplier group each claiming the same steel once this one is re-sent to a different merchant.
         */
        DetachBarsFromOrder::run($order);

        /*
         * Sending the order set every approval on the batch to true (OrderSentController), so undoing it
         * has to put them back - otherwise re-sending skips the project manager approval that the
         * confirm dialog exists to collect.
         *
         * Only once NOTHING on this batch is still on order, though. An approval is a fact about the
         * batch rather than about one supplier group - OrderApproval is keyed on (batch, project) - so
         * clearing it while another group's order stands said that nobody had approved materials that
         * were, at that moment, on their way. It also threw away approved_by_user_id and approved_at,
         * which is the record of who committed their colleagues, and re-sending the undone group would
         * put this user's name against an approval the first one had collected.
         */
        $stillOnOrder = $batch->orders()->where('order_sent', true)->exists();

        if (! $stillOnOrder) {
            UpdateOrderApprovalStatus::run($batch, false);
        }

        return back();
    }
}
