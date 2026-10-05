<?php

namespace App\Http\Controllers;

use App\Models\Batch;
use App\PrerequisiteConditions\PrerequisiteConditions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class BatchMarkCutController extends Controller
{
    /**
     * "Cut" on the Nesting page's card menu - the saw has been through this batch.
     *
     * The one mark on that menu that is not about buying, and so the one offered on every delivered
     * batch rather than only on the batches bought off the application: steel ordered through the
     * quotes screen and booked in on its goods receipts gets cut in the same shop by the same people,
     * and until now there was nowhere to say it had been.
     *
     * It changes nothing else. The batch stays live and stays on the page - the offcuts it produced
     * were created when it was nested, the board's columns are built on orders and receipts, and a
     * fully delivered batch with real orders behind it still closes itself on those receipts
     * (App\Services\DeliveredBatchAutoDone). This is a date and a name against a job.
     */
    public function __invoke(Request $request, Batch $batch): RedirectResponse
    {
        Gate::authorize('owned', $batch);

        $user = $request->user();

        /*
         * The gate is asked whether the batch is delivered, rather than trusting the card that drew
         * the button: the page knows it from the pill it has already worked out, and a press arriving
         * here has only the batch. See Batch::isDelivered for the three ways of being delivered.
         */
        abort_if(
            ! (new PrerequisiteConditions)->markBatchCut($user, $batch, $batch->isDelivered($user->business)),
            403,
        );

        $batch->update([
            'cut_at' => now(),
            'cut_by_user_id' => $user->id,
        ]);

        return back();
    }
}
