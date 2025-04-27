<?php

namespace App\Actions\Bar;

use App\Formatters\UniqueLetterIDGenerator;
use App\Models\Bar;
use App\Models\Batch;
use App\Models\Offcut;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsAction;

class CreateBarsAndOffcuts
{
    use AsAction;

    public function handle(Collection $meterageNesting, Batch $batch): void
    {
        /**
         * Create bars and offcuts
         */
        if($meterageNesting->count() > 0){
            foreach($meterageNesting as $index => $product){
                /*
                 * Utilised bars
                 */
                $utilisedBars = $product->nested['utilisedBars'];
                foreach($utilisedBars as $indexUtilisedBar => $utilisedBar){
                    $unused = $utilisedBar["result"]["unused"];
                    $threshold = $utilisedBar["result"]["scrap_threshold_mm"];

                    for ($i = 1; $i <= $utilisedBar["count"]; $i++) {
                        /*
                         * Create bar
                         * todo "BAR" is not fully implemented yet. It will replace serialization in the future
                         */
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
                            //Unique mark
                            $uniqueMark = (new UniqueLetterIDGenerator())->generate($product->product_category);

                            //Create offcut
                            $offcut = Offcut::create([
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

                                //Unique mark
                                "unique_mark" => $uniqueMark,
                            ]);

                            //Add ID to serialised nesting data
                            $meterageNesting[$index]->nested["utilisedBars"][$indexUtilisedBar]["result"]["offcut_id"] = $offcut->id;
                            $meterageNesting[$index]->nested["utilisedBars"][$indexUtilisedBar]["result"]["unique_mark"] = $uniqueMark;
                        }
                    }
                }

                /*
                 * Offcuts
                 */
                $bestResultOffcuts = $product->nested['bestResultOffcuts'];
                if(count($bestResultOffcuts['utilisedOffcutBars']) > 0){
                    //Loop individual offcuts
                    foreach($bestResultOffcuts['utilisedOffcutBars'] as $indexOffcut => $offcutData) {
                        /*
                         * Assign source offcut to batch
                         */
                        $offcut = Offcut::find($offcutData["sourceOffcut"]["offcutId"]);
                        if ($offcut) {
                            $offcut->batch_to_id = $batch->id;
                            $offcut->save();
                        }
                        else {
                            Log::error("Offcut with ID wasn't found: ", [$offcutData["sourceOffcut"]["offcutId"]]);
                        }

                        /*
                         * Create offcut-of-offcut
                         */
                        $offcutOfOffcutLength = $offcutData["offcutFromOffcut"]["reusableLength"];
                        if($offcutOfOffcutLength > 0){
                            //Unique mark
                            $uniqueMark = (new UniqueLetterIDGenerator())->generate($product->product_category);

                            //Create offcut
                            $offcutOfOffcut = Offcut::create([
                                //Batch
                                'batch_from_id' => $batch->id,
                                'batch_to_id' => null,

                                //Piece
                                'piece_to_id' => null, //todo this is not assigned anywhere as of yet, so its pointless

                                //Bar
                                "bar_id" => $offcutData["sourceOffcut"]["offcutId"],

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
                                'length' => $offcutOfOffcutLength,

                                //Unique mark
                                "unique_mark" => $uniqueMark,
                            ]);

                            //Add ID to serialised nesting data
                            $meterageNesting[$index]->nested["bestResultOffcuts"]["utilisedOffcutBars"][$indexOffcut]["offcutFromOffcut"]["offcut_of_offcut_id"] = $offcutOfOffcut->id;
                            $meterageNesting[$index]->nested["bestResultOffcuts"]["utilisedOffcutBars"][$indexOffcut]["offcutFromOffcut"]["unique_mark"] = $uniqueMark;
                        }
                    }
                }
            }
        }

        //Save nested_state as serialized data
        $batch->nested_state = serialize(["METERAGE" => $meterageNesting->toArray()]);
        $batch->save();
    }
}
