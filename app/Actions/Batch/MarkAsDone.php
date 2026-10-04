<?php

namespace App\Actions\Batch;

use App\Models\Batch;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Close a batch and send its projects to Past Projects.
 *
 * "done" is written here and nowhere else, and nothing in the application sets it back, so this is
 * the one-way door off the kanban. It is an action rather than two lines in a controller because
 * there are two ways through it now: the "Move to done" button, and
 * App\Services\DeliveredBatchAutoDone, which closes a batch whose steel has all been in for days.
 * Both have to apply the same guard - a batch with a delivery still out must not leave the board by
 * either route, and a second copy of that test is a second copy to get wrong.
 *
 * Returns false when the guard refuses, which the button's controller turns into a 403 and the sweep
 * treats as "not yet". Nothing is written in that case.
 */
class MarkAsDone
{
    use AsAction;

    public function handle(Batch $batch): bool
    {
        /*
         * Read off the SENT orders rather than the kanban's own
         * "delivered rows === unique supplier categories" sum: that compares two different units, and
         * it reads 0 === 0 for a batch nested entirely out of offcuts - which places no order at all
         * and still has to be closeable.
         */
        $hasUndeliveredOrder = $batch->orders()
            ->where('order_sent', true)
            ->where('is_delivered', false)
            ->exists();

        if ($hasUndeliveredOrder) {
            return false;
        }

        //Idempotent: a replayed post or a second sweep on the same batch changes nothing
        if ($batch->done) {
            return true;
        }

        $batch->done = true;
        $batch->save();

        return true;
    }
}
