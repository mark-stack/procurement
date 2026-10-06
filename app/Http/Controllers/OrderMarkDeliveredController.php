<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReceiveOrderRequest;
use App\Models\Order;
use App\Services\BatchMeasurements;
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
        });

        /*
         * The batch this receipt may have just completed.
         *
         * A batch is delivered when every sent order on it has been booked in, so the receipt that
         * books in the last one is the moment its steel is all in the yard - and the moment the
         * promise it was bought against can be measured. Asked of the batch rather than assumed from
         * this receipt, because a job usually buys from more than one merchant; see
         * Services\BatchMeasurements, which the two by-hand marks call in exactly the same way.
         */
        if ($order->batch !== null) {
            (new BatchMeasurements)->recordDelivery($order->batch, $request->user()->business);
        }

        /*
         * The mill certificates are not written here. The delivery screen attaches them through
         * MaterialCertificateController as they are picked, which is what lets a load that arrived
         * yesterday take the PDF the merchant emailed this morning - a receipt is finished, the
         * paperwork behind it is not.
         */

        return back()->with('success', $this->confirmation($order));
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
            .'delivered - the nest will use it - so chase this with the supplier separately.';
    }
}
