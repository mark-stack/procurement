<?php

namespace App\Actions\Offcut;

use App\Formatters\UniqueLetterIDGenerator;
use App\Models\Batch;
use App\Models\Offcut;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * @deprecated replaced with Actions/Bar/CreateBars
 */
class GenerateOffcuts
{
    use AsAction;

    public function handle(Batch $batch): void
    {
        /**
         * Generate offcuts (meterage items)
         */

        //Array of nesting data
        //todo refactor this to collecting 'BAR' and 'OFFCUT' items
        $nestedPiecesData = unserialize($batch->nested_state);
        $meterageProducts = ($batch->nested_state && $nestedPiecesData)
            ? $nestedPiecesData["METERAGE"]
            : [];

        //Loop products e.g "PFC"
        foreach($meterageProducts as $meterageProduct){
            $utilisedBars = $meterageProduct->nested["utilisedBars"];

            /*
             * Find the unused amount above the scrap threshold
             */
            foreach($utilisedBars as $bar){
                $qty = $bar["count"];
                $unused = $bar["result"]["unused"];
                $threshold = $bar["result"]["scrap_threshold_mm"];

                //Should make offcut
                if($unused >= $threshold){
                    //Multiple items
                    for ($i = 0; $i < $qty; $i++) {
                        Offcut::create([
                            //Batch
                            'batch_from_id' => $batch->id,
                            'batch_to_id' => null,

                            //Piece
                            'piece_to_id' => null,

                            //Bar
                            "bar_id" => $bar->id,

                            //Product attributes
                            'product_category' => $meterageProduct->product_category,
                            'material' => $meterageProduct->material ?? null,
                            'grade' => $meterageProduct->grade ?? null,
                            'surface' => $meterageProduct->surface ?? null,
                            'nominal_length' => $meterageProduct->nominal_length ?? null,
                            'precise_length' => $meterageProduct->precise_length ?? null,
                            'nominal_width' => $meterageProduct->nominal_width ?? null,
                            'precise_width' => $meterageProduct->precise_width ?? null,
                            'nominal_height' => $meterageProduct->nominal_height ?? null,
                            'precise_height' => $meterageProduct->precise_height ?? null,
                            'wall' => $meterageProduct->wall ?? null,
                            'length' => $unused,

                            //Unique mark
                            "unique_mark" => (new UniqueLetterIDGenerator())->generate($meterageProduct->product_category),
                        ]);
                    }
                }
            }
        }
    }
}
