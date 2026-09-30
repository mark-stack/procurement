<?php

namespace App\Actions\Batch;

use App\Models\Batch;
use App\Models\Business;
use Lorisleiva\Actions\Concerns\AsAction;

class DeleteBatchesWithoutPieces
{
    use AsAction;

    /**
     * Tidy up any batch of THIS business that the caller has just emptied of materials.
     *
     * Scoped to one business, and to batches rather than every row in the table. This walked
     * Batch::all() and deleted any batch anywhere with no pieces - so one project manager clearing a
     * few BOM rows reached into every other business's data, on a request that had nothing to do with
     * them. Nothing about "this batch has no pieces" makes it safe to delete on a stranger's behalf: a
     * batch is empty for a moment while it is being built (QuoteController attaches its pieces after
     * creating it), and the only thing that kept this from deleting one mid-flight was the transaction
     * it happens to run inside.
     *
     * One query for the emptied batches rather than a pieces count per batch.
     */
    public function handle(Business $business): void
    {
        $emptyBatches = $business->batches()
            ->doesntHave('pieces')
            ->get();

        foreach ($emptyBatches as $batch) {
            /** @var Batch $batch */
            //Delete associated order approvals
            $batch->orderApprovals()->delete();

            //Delete batch
            $batch->delete();
        }
    }
}
