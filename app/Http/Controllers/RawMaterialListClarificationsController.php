<?php

namespace App\Http\Controllers;

use App\Enums\GradeEnums;
use App\Enums\MaterialEnums;
use App\Enums\MeasurementUnitEnums;
use App\Enums\SurfaceEnums;
use App\Models\Business;
use App\Models\RawMaterialQuote;
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
                            $generalProductMatches = $dataClassificationService->findGeneralProductMatches(
                                $user,
                                $selectedProduct["product_category"],
                                MaterialEnums::from($selectedProduct["material"]),
                                [GradeEnums::from($selectedProduct["grade"])],
                                SurfaceEnums::from($selectedProduct["surface"]),
                                isset($selectedProduct["nominal_units"]) ? MeasurementUnitEnums::from($selectedProduct["nominal_units"]) : null,
                                $selectedProduct["nominal_length"] ?? null,
                                $selectedProduct["nominal_width"] ?? null,
                                $selectedProduct["nominal_height"] ?? null,
                                $selectedProduct["wall"] ?? null,
                                $selectedProduct["kg_per_m"] ?? null,
                            );

                            if($generalProductMatches->count() === 1){
                                $rawMaterialQuote->general_product_matches = serialize($generalProductMatches->toArray());
                                $rawMaterialQuote->save();
                            }
                        }
                    }
                }
            }
        }

        return back();
    }
}
