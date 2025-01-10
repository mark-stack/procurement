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
use App\Services\PieceService;
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
        $pieceService = new PieceService();

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
                            $empty = [
                                "allFields" => false,
                                "allFieldsIndividual" => [],
                                "results" => [],
                            ];

                            $rawMaterialQuote->general_product_matches = serialize($empty);
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

                            if(count($generalProductMatches["results"]) === 1 && $generalProductMatches["allFields"]){
                                $rawMaterialQuote->general_product_matches = serialize($generalProductMatches);
                                $rawMaterialQuote->save();

                                $algo = $formData["data"]["nesting_algo"];

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

                                $productSpec = $generalProductMatches["results"][0];
                                $piece = $pieceService->createPieceFromProductSpec($productSpec,$rawMaterialQuote,$algo);
                            }
                        }
                    }
                }
            }
        }

        return back();
    }
}
