<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReceiveOrderRequest;
use App\Models\Bar;
use App\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class OrderMarkDeliveredController extends Controller
{
    public function __invoke(ReceiveOrderRequest $request, Order $order): RedirectResponse
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
         *
         * Idempotent on the RECEIPT as well, and that is the part worth being careful about. A second
         * post must not overwrite the docket number, the checks or the name of whoever booked the steel
         * in - the record of a receipt belongs to the moment it was made. Correcting one is not
         * something this screen offers, for the same reason a placed order's certificate cannot be
         * deleted: it is a record, not a draft.
         */
        if ($order->is_delivered) {
            return back();
        }

        DB::transaction(function () use ($order, $request) {
            //Mark delivered
            //Offcuts are created up front by Actions/Bar/CreateBarsAndOffcuts when the batch is nested;
            //delivery only decides whether they count as available (see Business::availableOffcuts)
            $order->is_delivered = true;

            /*
             * The receipt itself. received_at and received_by_user_id are what ISO 9001 8.6 asks for -
             * verification complete, and the person who authorised the release, retained - and they are
             * written together with the flag so there is no window in which steel is delivered by
             * nobody.
             */
            $order->received_at = now();
            $order->received_by_user_id = $request->user()->id;
            $order->delivery_docket_number = $request->docketNumber();
            $order->quantity_verified = $request->quantityVerified();
            $order->grade_verified = $request->gradeVerified();
            $order->receipt_nonconformance = $request->nonconformance()?->value;
            $order->receipt_note = $request->note();

            $order->save();

            $this->recordHeatNumbers($order, $request->heatNumbers());
        });

        return back()->with('success', $this->confirmation($order));
    }

    /**
     * Write the heat numbers off the docket onto the bars this order bought.
     *
     * Scoped to the order's own bars by the query rather than by trusting the keys: the ids arrive in
     * a request body, and a bar id that is not this order's is somebody else's steel - at best a
     * colleague's, at worst another business's. Anything that does not match is dropped silently,
     * because the only way to send one is to have tampered with the form.
     *
     * Updated one at a time rather than in a single upsert so that each write goes through the model
     * and lands in the change log: a heat number is the last link in the traceability chain, and
     * "somebody changed it afterwards" is precisely the question that gets asked about it.
     *
     * @param  array<int, string>  $heatNumbers
     */
    private function recordHeatNumbers(Order $order, array $heatNumbers): void
    {
        if ($heatNumbers === []) {
            return;
        }

        $bars = Bar::query()
            ->where('order_id', $order->id)
            ->whereIn('id', array_keys($heatNumbers))
            ->get();

        foreach ($bars as $bar) {
            $bar->heat_number = $heatNumbers[$bar->id];
            $bar->save();
        }
    }

    /**
     * What the page says back.
     *
     * A delivery with a fault recorded against it is the one case worth being loud about: the steel is
     * in the yard and booked in, the nest will go on planning cuts out of it, and somebody has to chase
     * the merchant. Saying "delivered" and nothing else is how that gets forgotten by Thursday.
     */
    private function confirmation(Order $order): string
    {
        $nonconformance = $order->receiptNonconformance();

        if ($nonconformance === null) {
            return 'Delivery booked in.';
        }

        return 'Delivery booked in, and recorded as "'.$nonconformance->label().'". The steel counts as '
            .'delivered - the nest will use it - so chase this with '
            .($order->supplier->name ?? 'the supplier').' separately.';
    }
}
