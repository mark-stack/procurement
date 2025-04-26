<?php

namespace App\Actions\Batch;

use App\Models\Batch;
use App\Models\Offcut;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsAction;

class AssignOffcutsToBatch
{
    use AsAction;

    public function handle(Collection $meterageNesting, Batch $batch): void
    {
        /**
         * Assign offcuts to this batch
         */
         if($meterageNesting->count() > 0){
             foreach($meterageNesting as $index => $product){
                 /*
                  * Offcuts
                  */
                 $bestResultOffcuts = $product->nested['bestResultOffcuts'];
                 if(count($bestResultOffcuts['utilisedOffcutBars']) > 0){
                     //Loop individual offcuts
                     foreach($bestResultOffcuts['utilisedOffcutBars'] as $offcutData) {
                         $offcut = Offcut::find($offcutData["sourceOffcut"]["offcutId"]);
                         if ($offcut) {
                             $offcut->batch_to_id = $batch->id;
                             $offcut->save();
                         }
                         else {
                             Log::error("Offcut with ID wasn't found: ", [$offcutData["sourceOffcut"]["offcutId"]]);
                         }
                     }
                 }
             }
         }
    }
}
