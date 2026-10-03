<?php

namespace App\Actions\Batch;

use App\Actions\OrderApproval\CreatePendingOrderApprovals;
use App\Actions\Piece\AttachPiecesToBatch;
use App\Models\Batch;
use App\Models\Business;
use App\Models\Piece;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * Take every project in the Nesting column into one batch, owned by one user.
 *
 * This was the body of QuoteController::store. There was a second way in for a while -
 * App\Services\FabricationDeadlineQuoting pressed the button on the business's behalf when a
 * project's fabrication start date got close enough - and it is still worth it being here on its
 * own: this is the only place the steel is claimed, so the locking below exists once rather than
 * once per caller. The schedule now warns instead of pressing, because creating a batch commits the
 * business to a purchase.
 *
 * Returns null when somebody else got the pieces first. That is not an error - their batch is a
 * perfectly good batch - so the caller reports it as news rather than as a failure.
 */
class StartQuoting
{
    use AsAction;

    /**
     * @param  Collection<int, Piece>  $piecesReadyForBatching
     */
    public function handle(User $user, Business $business, Collection $piecesReadyForBatching): ?Batch
    {
        return DB::transaction(function () use ($business, $user, $piecesReadyForBatching) {
            /*
             * Claim the steel before anything is created.
             *
             * Everything the caller reads happens outside this transaction, so two people pressing
             * "Start quoting" seconds apart - or one person double clicking, or a second tab, or a
             * retried request - both get here holding the same list of unbatched pieces. Nothing
             * then stopped the second one: the batch was
             * created first and the pieces were moved onto it by id, off whichever batch already had
             * them.
             *
             * What that left is not recoverable by anything in the application. The first batch kept
             * its order approvals, its saved nesting and the offcuts SaveNesting had already consumed
             * against it, while the pieces those offcuts were cut for now belonged to the second batch
             * - two batches in the Quoting column for one set of projects, one of them empty, and the
             * same steel quoted and ordered twice.
             *
             * FOR UPDATE makes the second caller wait here until the first commits, and it then reads
             * the batch_id the first one wrote. A short count means somebody got there first, and this
             * returns before a single row is written.
             */
            $stillUnbatched = Piece::query()
                ->whereIn('id', $piecesReadyForBatching->pluck('id'))
                ->whereNull('batch_id')
                ->lockForUpdate()
                ->count();

            if ($stillUnbatched !== $piecesReadyForBatching->count()) {
                return null;
            }

            /*
             * Create batch
             */
            $batch = Batch::create([
                'user_id' => $user->id,
            ]);

            /*
             * Attach pieces to batch.
             *
             * The count is checked rather than assumed. The lock above is what actually prevents the
             * race; this is the same question asked once more by the write itself, so a database or
             * driver that does not honour the lock still cannot get past it. Throwing rolls the batch
             * back.
             */
            $claimed = AttachPiecesToBatch::run($piecesReadyForBatching, $batch);

            if ($claimed !== $piecesReadyForBatching->count()) {
                throw new RuntimeException(
                    'Claimed '.$claimed.' of '.$piecesReadyForBatching->count().' pieces for batch '.$batch->id
                );
            }

            /*
             * Create pending order approvals - after the pieces, because the approvals are read off
             * them. See CreatePendingOrderApprovals for why they are not read off the projects the
             * caller gated on, which is a smaller set than what actually got nested.
             */
            CreatePendingOrderApprovals::run($batch);

            //Save the current nesting state (points offcuts to new batch)
            SaveNesting::run($piecesReadyForBatching, $batch, $business);

            return $batch;
        });
    }
}
