<?php

namespace App\Http\Controllers;

use App\Models\Batch;
use App\PrerequisiteConditions\PrerequisiteConditions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class BatchMarkOrderedController extends Controller
{
    /**
     * "All ordered" on the Nesting page's card menu - the material on this batch has been bought.
     *
     * The other half of BatchMarkQuotedController, and the same caveat in bigger letters: this places
     * no order. Nothing reaches a merchant, no order row is marked sent, no pieces or bars are
     * attached to one and nobody is notified - all of which OrderSentController does, because that one
     * is a real order to a real supplier. This is a record that the buying happened elsewhere.
     *
     * Which is why it does not move the batch along the board either. The columns are built on sent
     * orders and the goods receipts that follow them (App\Services\BatchStages), and a batch bought
     * off the application has none - so it stays where it is, and this says so on its card.
     *
     * Allowed without "All quoted" first: a shop that rings the merchant and buys in the one call has
     * skipped nothing, and the quoted mark is not a step this can check up on.
     */
    public function __invoke(Request $request, Batch $batch): RedirectResponse
    {
        Gate::authorize('owned', $batch);

        $user = $request->user();

        abort_if(! (new PrerequisiteConditions)->markBatchOrdered($user, $batch), 403);

        $batch->update([
            'ordered_at' => now(),
            'ordered_by_user_id' => $user->id,
        ]);

        return back();
    }
}
