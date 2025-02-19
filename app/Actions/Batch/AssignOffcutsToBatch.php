<?php

namespace App\Actions\Batch;

use App\Models\Batch;
use App\Models\Offcut;
use Illuminate\Support\Collection;
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
             foreach($meterageNesting as $product){
                 $bestResultOffcuts = $product->nested['bestResultOffcuts'];
                 if(count($bestResultOffcuts['utilisedOffcutBars']) > 0){
                     foreach($bestResultOffcuts['utilisedOffcutBars'] as $offcutData){
                         $offcut = Offcut::findOrFail($offcutData["offcutId"]);
                         $offcut->batch_to_id = $batch->id;
                         $offcut->save();
                     }
                 }
             }
         }
    }
}
