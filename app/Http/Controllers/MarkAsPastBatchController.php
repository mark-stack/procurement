<?php

namespace App\Http\Controllers;

use App\Actions\Batch\MarkAsDone;
use App\Models\Batch;
use App\PrerequisiteConditions\PrerequisiteConditions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class MarkAsPastBatchController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, Batch $batch): RedirectResponse
    {
        /*
         * The implicitly bound {batch} carries no business scoping, and this was the one batch
         * controller without the gate every other one has - so any onboarded user could close any
         * other business's live batch by id.
         */
        Gate::authorize('owned', $batch);

        /*
         * And the prerequisite the Nesting card draws the menu item from, asked again here the way
         * every other press on that menu asks it: a colleague with no job on the batch may not close
         * it, and a batch whose steel has not arrived is not finished whatever a stale tab believes.
         *
         * The gate is told whether the batch is delivered rather than working it out, because the
         * card already knows from the pill it drew - see Batch::isDelivered for the three ways of
         * being delivered and NestingIndexController::milestoneOf for how the card reads them.
         */
        abort_if(
            ! (new PrerequisiteConditions)->markBatchDone(
                $request->user(),
                $batch,
                $batch->isDelivered($request->user()->business),
            ),
            403,
        );

        /*
         * Through the action, because App\Services\DeliveredBatchAutoDone closes batches on this
         * business's behalf five days after the last delivery and the two routes have to apply the one
         * guard. False is that guard refusing: the card only offers "Move to done" once the deliveries
         * are in, but that is the button's own state - a stale tab or a replayed post reaches this with
         * nothing else to stop it.
         */
        abort_if(! MarkAsDone::run($batch), 403, 'This batch still has materials out for delivery.');

        //The card just disappears off the board otherwise, with nothing to say where it went
        return back()->with(
            'success',
            "Batch {$batch->id} has been moved to done. You'll find it under Past Batches.",
        );
    }
}
