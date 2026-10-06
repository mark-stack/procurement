<?php

namespace App\Http\Controllers;

use App\Models\Batch;
use App\PrerequisiteConditions\PrerequisiteConditions;
use App\Services\BatchMeasurements;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class BatchMarkDeliveredController extends Controller
{
    /**
     * "Delivered" on the Nesting page's card menu - the steel on this batch is in the rack.
     *
     * The third of the supplier-free marks, and it books in nothing: no goods receipt is written, no
     * order is flagged delivered, nobody is notified. A batch bought through the quotes and orders
     * screen reaches Delivered on its receipts instead (OrderMarkDeliveredController), which is why
     * the gate refuses this one the moment a real order has gone out - two answers to "has it
     * arrived" that can disagree is worse than one answer that is sometimes missing.
     *
     * Pressed from the certificates modal rather than from a plain confirm, because the question
     * "has it turned up" and the question "where is the paperwork" are asked at the same moment, by
     * the same person, standing in the same yard. The mark is what this writes; the certificates are
     * attached beside it and are not a condition of it - plenty of merchants send the PDF days later.
     */
    public function __invoke(Request $request, Batch $batch): RedirectResponse
    {
        Gate::authorize('owned', $batch);

        $user = $request->user();

        /*
         * "Has every merchant already been marked in" worked out here rather than taken from the card
         * that greyed the item, for the reason the two marks before it do the same: the colleague who
         * marked the last block delivered in the order list did it while this modal was open on
         * somebody else's screen.
         */
        abort_if(
            ! (new PrerequisiteConditions)->markBatchDelivered(
                $user,
                $batch,
                $batch->everySupplierGroupDelivered($user->business),
            ),
            403,
        );

        $batch->update([
            'delivered_at' => now(),
            'delivered_by_user_id' => $user->id,
        ]);

        /*
         * And the measurement that goes with it: the day the steel was wanted, the day it arrived,
         * and the gap. Taken now rather than worked out later, because the day it was wanted is the
         * earliest fabrication date on the batch and that date stays editable - see
         * Services\BatchMeasurements. Every press that can complete a delivery calls this; the one
         * that finishes the job is the one that records it.
         */
        (new BatchMeasurements)->recordDelivery($batch, $user->business);

        return back();
    }
}
