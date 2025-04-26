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

        //Pieces nested
        $piecesNested = $nestingFormatter->piecesNested($piecesReadyForBatching, $lettersProjectArray, $business);

        //Create bars, offcuts, and store nested state
        $meterageNesting = $piecesNested["METERAGE"] ?? collect([]);
        CreateBarsAndOffcuts::run($meterageNesting, $batch);

        //Assign offcuts to batch
        AssignOffcutsToBatch::run($meterageNesting, $batch);
    }
}
