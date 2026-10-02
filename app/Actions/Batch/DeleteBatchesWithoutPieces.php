<?php

namespace App\Actions\Batch;

use App\Models\Batch;
use App\Models\Business;
use App\Models\Offcut;
use App\Models\Scrap;
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
     * Empty of materials is not enough on its own either; see below.
     *
     * One query for the emptied batches rather than a pieces count per batch.
     */
    public function handle(Business $business): void
    {
        /*
         * No pieces, and nothing bought against it.
         *
         * "Has no materials on it" says what a batch is missing, not what it is. A batch that was
         * quoted and ordered still holds the record of that - the quote that went to the merchant, the
         * order, its goods receipt - and losing its pieces does not make any of that less true. One
         * was found live with no pieces, a sent quote, and an order sent and booked in as delivered:
         * steel that was bought and arrived.
         *
         * What kept this from deleting it was the foreign key on orders.batch_id, which fires as a 500
         * on whoever was deleting a BOM line at the time - and on everybody who tried after, the sweep
         * being over every empty batch the business has rather than the ones this request emptied.
         * Deleting the orders first to get past that is the worse half of the bargain: the constraint
         * is the only thing standing between a stale batch row and a deleted purchase.
         *
         * So a batch carrying quotes or orders is left exactly as it is, cards and all. It is the
         * emptying that is the bug - something took the pieces off a bought batch - and this is not the
         * place that can put them back. BatchController::destroy is the one path that may take a batch
         * apart, and it asks PrerequisiteConditions::undoStartQuoting first, which refuses once
         * anything has been ordered.
         */
        $emptyBatches = $business->batches()
            ->doesntHave('pieces')
            ->doesntHave('quotes')
            ->doesntHave('orders')
            ->get();

        foreach ($emptyBatches as $batch) {
            /** @var Batch $batch */
            //Delete associated order approvals
            $batch->orderApprovals()->delete();

            /*
             * And anything the batch's own nesting produced, which the batch row is the only way back
             * to. A shell batch should have none - offcuts, scrap and bars are written when a nest is
             * saved, and that is also when the quotes above appear, so a batch with neither has never
             * been nested. Done anyway because offcuts.batch_from_id and scraps.batch_id carry no
             * foreign key: nothing would stop the delete below, and an offcut left pointing at a batch
             * that is gone holds its unique mark against the pool for good, invisible on the offcuts
             * index. The bars go with the batch on their own - bars.batch_id cascades.
             */
            Offcut::query()
                ->where('batch_to_id', $batch->id)
                ->update(['batch_to_id' => null, 'piece_to_id' => null]);

            Offcut::query()->where('batch_from_id', $batch->id)->delete();
            Scrap::query()->where('batch_id', $batch->id)->delete();

            //Delete batch
            $batch->delete();
        }
    }
}
