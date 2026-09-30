<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class OrderMarkDeliveredController extends Controller
{
    public function __invoke(Request $request, Order $order): RedirectResponse
    {
        Gate::authorize('owned', $order);

        //Delivery only means anything for an order that was actually sent. The page hides the checkbox
        //until then, but the route is reachable directly
        abort_if(! $order->order_sent, 403, 'Order has not been sent');

        /*
         * Already delivered, so there is nothing to do.
         *
         * This used to flip the flag rather than set it, while the page disables the checkbox the moment
         * an order is delivered - so the only thing a second request could do was un-deliver steel that
         * had arrived, and the page offered no way to do it on purpose. It took the batch's offcuts back
         * out of inventory (Business::offcutsInInventory reads is_delivered) and re-opened the quote-sent
         * gate on an order that had already landed. Idempotent instead: a repeated post, a double click
         * or a replayed form changes nothing.
         */
        if ($order->is_delivered) {
            return back();
        }

        //Mark delivered
        //Offcuts are created up front by Actions/Bar/CreateBarsAndOffcuts when the batch is nested;
        //delivery only decides whether they count as available (see Business::availableOffcuts)
        $order->is_delivered = true;
        $order->save();

        return back();
    }
}
