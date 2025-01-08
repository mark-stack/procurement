<?php

namespace App\Http\Controllers;

use App\Enums\GradeEnums;
use App\Enums\MaterialEnums;
use App\Enums\MeasurementUnitEnums;
use App\Enums\SurfaceEnums;
use App\Models\Business;
use App\Models\Piece;
use App\Models\RawMaterialQuote;
use App\Services\CsvService;
use App\Services\DataClassificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RawMaterialListClarificationsController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, Business $business): RedirectResponse
    {
        /**
         * Single purpose: the user confirms exact product.
         * The user is given a bunch of partial matches to chose from to clarify.
         * It's saved by making the "general_product_matches" field = 1x product.
         */
        $user = auth()->user();

        //Services
        $dataClassificationService = new DataClassificationService();
        $csvService = new CsvService();

        foreach($request->all() as $index => $formData){
            //Delete items
            if($index === "deletedIds"){
                //Delete the "promised to delete" items
                RawMaterialQuote::query()->whereIn("id",$formData)->delete();
            }
            //Clarification items
            else{
                $id = isset($formData["data"]) ? $formData["data"]["id"] : null;

                if($id && !in_array($id,$request->deletedIds)){
                    $rawMaterialQuote = RawMaterialQuote::findOrFail($formData["data"]["id"]);

                    //Customise option (selected "other")
                    if($formData["selected"] === "customise"){
                        /**
                         * Custom product
                         */
                        $custom = $formData["custom"];
                        if($custom){
                            $rawMaterialQuote->custom_product_matches = serialize([]);
                            $rawMaterialQuote->save();
                        }
                        /**
                         * Regular product
                         */
                        else{
                            $rawMaterialQuote->general_product_matches = serialize([]);
                            $rawMaterialQuote->save();
                        }
                    }
                    //Selected a product
                    else{
                        $selectedProduct = $formData["options"][$formData["selected"]];

                        /**
                         * Custom product (won't have a general match)
                         */
                        $custom = $formData["custom"];
                        if($custom){
                            unset($selectedProduct["product_derived_label"]);
                            $customProductMatches = [$selectedProduct];

                            $rawMaterialQuote->custom_product_matches = serialize($customProductMatches);
                            $rawMaterialQuote->custom_confirmed = true;
                            $rawMaterialQuote->save();
                        }

                        /**
                         * Regular product (must have a single general match)
                         */
                        else{
                            $productCategory = $selectedProduct["product_category"];
                            $material = MaterialEnums::from($selectedProduct["material"]);
                            $grade = [GradeEnums::from($selectedProduct["grade"])];
                            $surface = SurfaceEnums::from($selectedProduct["surface"]);
                            $nominalUnits = isset($selectedProduct["nominal_units"]) ? MeasurementUnitEnums::from($selectedProduct["nominal_units"]) : null;
                            $uncertainLengthFloat = $selectedProduct["nominal_length"] ?? null;
                            $uncertainWidthFloat = $selectedProduct["nominal_width"] ?? null;
                            $uncertainHeightFloat = $selectedProduct["nominal_height"] ?? null;
                            $wall = $selectedProduct["wall"] ?? null;
                            $kg_per_m = $selectedProduct["kg_per_m"] ?? null;

                            $generalProductMatches = $dataClassificationService->findGeneralProductMatches(
                                $user,
                                $productCategory,
                                $material,
                                $grade,
                                $surface,
                                $nominalUnits,
                                $uncertainLengthFloat,
                                $uncertainWidthFloat,
                                $uncertainHeightFloat,
                                $wall,
                                $kg_per_m,
                            );

                            if($generalProductMatches->count() === 1){
                                $rawMaterialQuote->general_product_matches = serialize($generalProductMatches->toArray());
                                $rawMaterialQuote->save();

                                $algo = $formData["data"]["nesting_algo"];

                                dd(1,$rawMaterialQuote);
//                                dd([
//                                    $productCategory,
//                                    $material,
//                                    $grade,
//                                    $surface,
//                                    $nominalUnits,
//                                    $uncertainLengthFloat,
//                                    $uncertainWidthFloat,
//                                    $uncertainHeightFloat,
//                                    $wall,
//                                    $kg_per_m,
//                                    $algo
//                                ]);

                                //Create piece
                                $piece = Piece::create([
                                    'project_id' => $rawMaterialQuote->project_id,
                                    "raw_material_quote_id" => $rawMaterialQuote->id,
                                    "product_category" => $rawMaterialQuote->product_category,
                                    "material" => $rawMaterialQuote->material,
                                    "grade" => $rawMaterialQuote->grade,
                                    "surface" => $rawMaterialQuote->surface,
                                    "nominal_units" => $rawMaterialQuote->nominal_units,
                                    "nesting_algo" => $algo,
                                    "nominal_length" => $uncertainLengthFloat,
                                    "precise_length" => null, //todo
                                    "nominal_width" => $uncertainWidthFloat,
                                    "precise_width" => null, //todo
                                    "nominal_height" => $uncertainHeightFloat,
                                    "precise_height" => null, //todo
                                    "actual_length" => $rawMaterialQuote->length_required,
                                    "actual_width" => $rawMaterialQuote->width_required,
                                    "wall" => $wall,
                                    "kg_per_m" => $kg_per_m,
                                    "actual_qty" => $rawMaterialQuote->sub_qty,
                                ]);
                            }
                        }
                    }
                }
            }
        }

        return back();
    }
}
