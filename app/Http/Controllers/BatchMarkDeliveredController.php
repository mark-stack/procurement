<?php

namespace App\Http\Controllers;

use App\Models\Batch;
use App\PrerequisiteConditions\PrerequisiteConditions;
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

        abort_if(! (new PrerequisiteConditions)->markBatchDelivered($user, $batch), 403);

        $batch->update([
            'delivered_at' => now(),
            'delivered_by_user_id' => $user->id,
        ]);

        return back();
    }
}
