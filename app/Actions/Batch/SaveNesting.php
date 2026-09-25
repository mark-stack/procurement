<?php

namespace App\Actions\Batch;

use App\Actions\Bar\CreateBarsAndOffcuts;
use App\Formatters\NestingFormatter;
use App\Models\Batch;
use App\Models\Business;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsAction;

class SaveNesting
{
    use AsAction;

    public function handle(Collection $piecesReadyForBatching, Batch $batch, Business $business): void
    {
        //Services
        $nestingFormatter = new NestingFormatter();

        //Letter-project array
        $lettersProjectArray = $nestingFormatter->getLetterProjectArray($piecesReadyForBatching);

        /*
         * Pieces nested.
         *
         * This re-runs the nest the user was shown on the "suggested nesting" screen. It matches what
         * they approved because meterageAlgorithm() seeds its randomiser from the pieces themselves.
         */
        $piecesNested = $nestingFormatter->piecesNested($piecesReadyForBatching, $lettersProjectArray, $business);

        /*
         * Create bars and offcuts, assign source offcuts to this batch, and store the nested state.
         * Only meterage produces bars and offcuts, but every algo is persisted so the batch view shows
         * the same materials the suggestion did.
         */
        CreateBarsAndOffcuts::run($piecesNested, $batch);
    }
}
