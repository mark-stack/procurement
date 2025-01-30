<?php

namespace App\Actions\Piece;

use App\Models\Batch;
use App\Models\Piece;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsAction;

class AttachPiecesToBatch
{
    use AsAction;

    public function handle(Collection $piecesReadyForBatching, Batch $batch): void
    {
        /**
         * Attach PIECE to BATCH.
         */
        Piece::query()
            ->whereIn('id', $piecesReadyForBatching->pluck('id'))
            ->update([
                'batch_id' => $batch->id,
            ]);
    }
}
