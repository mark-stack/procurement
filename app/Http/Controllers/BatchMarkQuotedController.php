<?php

namespace App\Http\Controllers;

use App\Models\Batch;
use App\PrerequisiteConditions\PrerequisiteConditions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class BatchMarkQuotedController extends Controller
{
    /**
     * "All quoted" on the Nesting page's card menu - the prices for this batch are in.
     *
     * Deliberately not a loop over QuoteController::update. That endpoint marks one merchant's quote
     * as sent, and a shop that does not buy through the quotes modal has no merchant rows to mark: the
     * suppliers were never entered, so there is nothing to tick and no way past the Quoting column.
     * This records the step itself, on the batch, naming nobody - see the 2026_10_03 migration.
     *
     * Nothing is sent anywhere by it, no quote row is touched, and the batch does not move column:
     * BatchStages reads sent orders, and this is not one. All it changes is what the card says, which
     * is what it claims to change.
     */
    public function __invoke(Request $request, Batch $batch): RedirectResponse
    {
        Gate::authorize('owned', $batch);

        $user = $request->user();

        /*
         * The same gate the card greys the item with, asked again here - a menu item is a suggestion,
         * and this is the thing that decides. See PrerequisiteConditions::markBatchQuoted.
         *
         * "Is every merchant already priced" is worked out for this batch rather than taken from the
         * card, which is where the page reads it off its own pill. A stale menu is exactly the case
         * this is guarding: the colleague who marked the last block quoted in the order list did it
         * on somebody else's open dropdown.
         */
        abort_if(
            ! (new PrerequisiteConditions)->markBatchQuoted(
                $user,
                $batch,
                $batch->everySupplierGroupPriced($user->business),
            ),
            403,
        );

        $batch->update([
            'quoted_at' => now(),
            'quoted_by_user_id' => $user->id,
        ]);

        return back();
    }
}
