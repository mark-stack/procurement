<?php

namespace App\Actions\Bar;

use App\Models\Bar;
use App\Models\Batch;
use App\Models\Offcut;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsAction;

class CreateBars
{
    use AsAction;

    public function handle(Collection $meterageNesting, Batch $batch): void
    {
        /**
         * Create bars with offcut
         */
        if($meterageNesting->count() > 0){
            foreach($meterageNesting as $product){
                $utilisedBars = $product->nested['utilisedBars'];

                foreach($utilisedBars as $utilisedBar){
                    $unused = $utilisedBar["result"]["unused"];
                    $threshold = $utilisedBar["result"]["scrap_threshold_mm"];

                    for ($i = 1; $i <= $utilisedBar["count"]; $i++) {
                        //Create bar
                        $bar = Bar::create([
                            //Product attributes
                            'product_category' => $product->product_category,       //PFC
                            'material' => $product->material ?? null,               //PLAIN CARBON STEEL
                            'grade' => $product->grade ?? null,                     //GR250
                            'surface' => $product->surface ?? null,                 //NONE
                            'nominal_length' => $product->nominal_length ?? null,   //9000
                            'precise_length' => $product->precise_length ?? null,   //
                            'nominal_width' => $product->nominal_width ?? null,     //
                            'precise_width' => $product->precise_width ?? null,     //
                            'nominal_height' => $product->nominal_height ?? null,   //200
                            'precise_height' => $product->precise_height ?? null,   //
                            'wall' => $product->wall ?? null,                       //

                            //Other
                            "product_derived_label" => $product->product_derived_label, //200PFC
                            "length" => $utilisedBar["result"]["bar_length"],
                        ]);

                        //Should make offcut
                        if($unused >= $threshold){
                            Offcut::create([
                                //Batch
                                'batch_from_id' => $batch->id,
                                'batch_to_id' => null,

                                //Piece
                                'piece_to_id' => null, //todo this is not assigned anywhere as of yet, so its pointless

                                //Bar
                                "bar_id" => $bar->id,

                                //Product attributes
                                'product_category' => $product->product_category,
                                'material' => $product->material ?? null,
                                'grade' => $product->grade ?? null,
                                'surface' => $product->surface ?? null,
                                'nominal_length' => $product->nominal_length ?? null,
                                'precise_length' => $product->precise_length ?? null,
                                'nominal_width' => $product->nominal_width ?? null,
                                'precise_width' => $product->precise_width ?? null,
                                'nominal_height' => $product->nominal_height ?? null,
                                'precise_height' => $product->precise_height ?? null,
                                'wall' => $product->wall ?? null,
                                'length' => $unused,
                            ]);
                        }
                    }
                }
            }
        }
    }
}
