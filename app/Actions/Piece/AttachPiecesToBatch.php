<?php

namespace App\Actions\Piece;

use App\Models\Batch;
use App\Models\Piece;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsAction;

class AttachPiecesToBatch
{
    use AsAction;

    /**
     * Claim these pieces for this batch, and report how many were actually claimed.
     *
     * "whereNull" is the whole point, and it is what makes the claim atomic rather than a guess.
     * This used to update by id alone, so a piece already sitting on another batch was moved onto
     * this one - and the caller had no way to find out. Two people pressing "Start quoting" within
     * a second of each other both read the same unbatched pieces (the read happens outside the
     * transaction), and the second write took the steel off the batch the first one had already
     * nested, costed and consumed offcuts against. That left an empty batch on the shared board
     * holding the offcuts, the approvals and the certificate trail for steel now cut on a different
     * batch, and nothing in the application can unwind it.
     *
     * With the column in the WHERE clause the database settles it: the second UPDATE waits on the
     * first transaction's row locks, then matches nothing, and the count says so. See
     * QuoteController::store for what it does with a short count.
     *
     * @param  Collection<int, Piece>  $piecesReadyForBatching
     * @return int how many pieces this batch now owns
     */
    public function handle(Collection $piecesReadyForBatching, Batch $batch): int
    {
        return Piece::query()
            ->whereIn('id', $piecesReadyForBatching->pluck('id'))
            ->whereNull('batch_id')
            ->update([
                'batch_id' => $batch->id,
            ]);
    }
}
