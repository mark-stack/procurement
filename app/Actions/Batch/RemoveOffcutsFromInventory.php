<?php

namespace App\Actions\Batch;

use App\Models\Batch;
use Lorisleiva\Actions\Concerns\AsAction;

class RemoveOffcutsFromInventory
{
    use AsAction;

    public function handle(array $piecesNested): void
    {
        dd(3,$piecesNested);

        //nested > utilisedBars > bestResultOffcuts > utilisedOffcutBars
    }
}
