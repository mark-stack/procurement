<?php

namespace App\Actions\Piece;

use App\Models\Batch;
use Lorisleiva\Actions\Concerns\AsAction;

class DetachPiecesFromBatch
{
    use AsAction;

    public function handle(Batch $batch): void
    {
        /**
         * Detach PIECE from BATCH.
         */
        $batch->pieces()->update([
            'batch_id' => null,
        ]);
    }
}
