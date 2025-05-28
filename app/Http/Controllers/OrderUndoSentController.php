<?php

namespace App\Http\Controllers;

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

        $order->order_sent = false;
        $order->is_delivered = false;
        $order->save();

        //Detach pieces to order
        DetachPiecesFromOrder::run($order->batch, $order);

        return back();
    }
}
