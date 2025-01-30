<?php

namespace App\Actions\Batch;

use App\Models\Batch;
use Lorisleiva\Actions\Concerns\AsAction;

class DeleteBatchesWithoutPieces
{
    use AsAction;

    public function handle(): void
    {
        foreach (Batch::all() as $batch) {
            if ($batch->pieces()->count() === 0) {
                //Delete associated order approvals
                $batch->orderApprovals()->delete();

                //Delete batch
                $batch->delete();
            }
        }
    }
}
