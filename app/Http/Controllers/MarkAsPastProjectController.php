<?php

namespace App\Http\Controllers;

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
         * "done" is written here and nowhere else, and nothing in the app sets it back, so the batch
         * leaves the kanban for good. The two cards only offer "Move to done" once the deliveries are
         * in, but that is the button's own state - a stale tab or a replayed post reached this with
         * nothing to stop it.
         *
         * Read off the SENT orders rather than the kanban's own
         * "delivered rows === unique supplier categories" sum: that compares two different units, and
         * it reads 0 === 0 for a batch nested entirely out of offcuts - which places no order at all
         * and still has to be closeable.
         */
        $hasUndeliveredOrder = $batch->orders()
            ->where('order_sent', true)
            ->where('is_delivered', false)
            ->exists();

        abort_if($hasUndeliveredOrder, 403, 'This batch still has materials out for delivery.');

        $batch->done = true;
        $batch->save();

        return back();
    }
}
