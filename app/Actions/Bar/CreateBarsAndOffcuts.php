<?php

namespace App\Actions\Bar;

use App\Enums\NestingEnums;
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

    public function handle(array $piecesNested, Batch $batch, array $lettersProjectArray = []): void
    {
        /**
         * Create bars and offcuts
         *
         * Only meterage has bars and offcuts, but the whole nest is serialised so that bundle and area
         * materials survive onto the batch too.
         */
        $meterageNesting = $piecesNested[NestingEnums::METERAGE->value] ?? collect([]);

        if($meterageNesting->count() > 0){
            foreach($meterageNesting as $index => $product){
                /*
                 * Utilised bars
                 */
                $utilisedBars = $product->nested['utilisedBars'];
                foreach($utilisedBars as $indexUtilisedBar => $utilisedBar){
                    $unused = $utilisedBar["result"]["unused"];
                    $threshold = $utilisedBar["result"]["scrap_threshold_mm"];

                    /*
                     * "count" identical bars each produce their own offcut, so collect every id rather
                     * than writing one key that each pass overwrites.
                     */
                    $offcutIds = [];
                    $uniqueMarks = [];

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

                            $offcutIds[] = $offcut->id;
                            $uniqueMarks[] = $uniqueMark;
                        }
                    }

                    //Add IDs to serialised nesting data
                    if(count($offcutIds) > 0){
                        $nested = $meterageNesting[$index]->nested;
                        $nested["utilisedBars"][$indexUtilisedBar]["result"]["offcut_id"] = $offcutIds[0];
                        $nested["utilisedBars"][$indexUtilisedBar]["result"]["offcut_ids"] = $offcutIds;
                        $nested["utilisedBars"][$indexUtilisedBar]["result"]["unique_mark"] = $uniqueMarks[0];
                        $nested["utilisedBars"][$indexUtilisedBar]["result"]["unique_marks"] = $uniqueMarks;
                        $meterageNesting[$index]->nested = $nested;
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
                            $nested = $meterageNesting[$index]->nested;
                            $nested["bestResultOffcuts"]["utilisedOffcutBars"][$indexOffcut]["offcutFromOffcut"]["offcut_of_offcut_id"] = $offcutOfOffcut->id;
                            $nested["bestResultOffcuts"]["utilisedOffcutBars"][$indexOffcut]["offcutFromOffcut"]["unique_mark"] = $uniqueMark;
                            $meterageNesting[$index]->nested = $nested;
                        }
                    }
                }
            }
        }

        /*
         * Save nested_state (the NestedState cast encodes it as JSON).
         * Every algo is kept, not just meterage, so bundle and area materials stay visible on the batch.
         */
        $nestedState = [];
        foreach($piecesNested as $algo => $items){
            $nestedState[$algo] = $items instanceof Collection
                ? $items->toArray()
                : $items;
        }
        $nestedState[NestingEnums::METERAGE->value] = $meterageNesting->toArray();

        $batch->nested_state = $nestedState;

        /*
         * Keep the letter map that was stamped onto these cuts. Recomputing it when the batch is read
         * back orders the projects differently, so the legend disagreed with the drawings.
         */
        $batch->letters_project_array = $lettersProjectArray;

        $batch->save();
    }
}
