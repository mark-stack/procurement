<?php

namespace App\Http\Controllers;

use App\Actions\Batch\MarkAsDone;
use App\Models\Batch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class MarkAsPastProjectController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Batch $batch): RedirectResponse
    {
        /*
         * The implicitly bound {batch} carries no business scoping, and this was the one batch
         * controller without the gate every other one has - so any onboarded user could close any
         * other business's live batch by id.
         */
        Gate::authorize('owned', $batch);

        /*
         * Through the action, because App\Services\DeliveredBatchArchiving closes batches on this
         * business's behalf five days after the last delivery and the two routes have to apply the one
         * guard. False is that guard refusing: the card only offers "Move to done" once the deliveries
         * are in, but that is the button's own state - a stale tab or a replayed post reaches this with
         * nothing else to stop it.
         */
        abort_if(! MarkAsDone::run($batch), 403, 'This batch still has materials out for delivery.');

        //The card just disappears off the board otherwise, with nothing to say where it went
        return back()->with(
            'success',
            "Batch {$batch->id} has been moved to done. You'll find it under Past Projects.",
        );
    }
}
