<?php

namespace App\Actions\Batch;

use App\Formatters\NestingFormatter;
use App\Models\Batch;
use App\Models\Business;
use App\Models\Offcut;
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
        if(isset($piecesNested["METERAGE"])){
            foreach($piecesNested["METERAGE"] as $product){
                $bestResultOffcuts = $product->nested['bestResultOffcuts'];
                if(count($bestResultOffcuts['utilisedOffcutBars']) > 0){
                    foreach($bestResultOffcuts['utilisedOffcutBars'] as $offcutData){
                        //todo debug
//                        if($product->product_derived_label === "75x50x2.5 RHS") { //"75x50x2.5 RHS","250PFC  "
//                            dd(3,$bestResultOffcuts['utilisedOffcutBars']);
//                        }

                        $offcut = Offcut::findOrFail($offcutData["offcutId"]);
                        $offcut->batch_to_id = $batch->id;
                        $offcut->save();
                    }
                }
            }
        }

        //Save nested state
        $batch->nested_state = serialize($piecesNested);
        $batch->save();

        //Update the offcut inventory to remove allocated offcuts from circulation
        RemoveOffcutsFromInventory::run($piecesNested);
    }
}
