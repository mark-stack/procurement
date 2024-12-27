<?php

namespace App\Http\Controllers;

use App\Enums\MeasurementUnitEnums;
use App\Enums\NestingEnums;
use App\Enums\SurfaceEnums;
use App\Models\Business;
use App\Models\Piece;
use App\Models\Product;
use App\Models\Project;
use App\Models\RawMaterialQuote;
use App\Services\NotificationService;
use App\Services\ProductService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Validator;

class RawMaterialListCustomisationsController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, Business $business): RedirectResponse
    {
        /**
         * Single purpose: save the non-price book product as user-custom product
         */
        $productService = new ProductService();
        $rows = $request->all();

        $validation = $productService->validationUserCustom($rows,$request->deletedIds);

        //Has errors
        if($validation['validationErrors'] > 0){
            throw new ValidationException($validation['validator']);
        }
        else{
            //Delete the "promised to delete" items
            RawMaterialQuote::query()->whereIn("id",$request->deletedIds)->delete();

            $user = auth()->user();

            foreach($rows as $formData){
                $id = isset($formData["data"]) ? $formData["data"]["id"] : null;

                if($id && !in_array($id,$request->deletedIds)){

                    //Prepare single product item
                    $preparedFormData = $this->preparedFormDataSingleProduct($formData);

                    //Prepare product variations (e.g lengths)
                    $productVariations = $this->productVariations($preparedFormData,$business);

                    //Create product variations
                    foreach($productVariations as $productVariation){
                        Product::create($productVariation);
                    }

                    /**
                     * General product matches
                     */
                    $generalProductMatches = [];
                    $nestingAlgo = $formData["selected"]["nesting_algo"];

                    //METERAGE
                    if($nestingAlgo === NestingEnums::METERAGE->value) {
                        //$sizeInclude = ["nominal_height"];
                        $generalProductMatches = Product::select('product', 'material', 'grade', 'surface', 'nominal_units', "nominal_height")
                            ->distinct()
                            ->availableFor($user)
                            ->where("product", $preparedFormData["product"])
                            ->where("material", $preparedFormData["material"])
                            ->where("grade", $preparedFormData["grade"])
                            ->where("surface", SurfaceEnums::NONE->value)
                            ->where("nominal_units", MeasurementUnitEnums::MILLIMETERS->value)
                            ->where("nominal_height",$preparedFormData["nominal_height"])
                            ->get();
                    }
                    //AREA
                    if($nestingAlgo === NestingEnums::AREA->value) {
                        //$sizeInclude = ["nominal_height"];
                        $generalProductMatches = Product::select('product', 'material', 'grade', 'surface', 'nominal_units', "nominal_height")
                            ->distinct()
                            ->availableFor($user)
                            ->where("product", $preparedFormData["product"])
                            ->where("material", $preparedFormData["material"])
                            ->where("grade", $preparedFormData["grade"])
                            ->where("surface", SurfaceEnums::NONE->value)
                            ->where("nominal_units", MeasurementUnitEnums::MILLIMETERS->value)
                            ->where("nominal_height",$preparedFormData["nominal_height"])
                            ->get();
                    }
                    //BUNDLE
                    if($nestingAlgo === NestingEnums::BUNDLE->value) {
                        //$sizeInclude = ["nominal_length","nominal_width"];
                        $generalProductMatches = Product::select('product', 'material', 'grade', 'surface', 'nominal_units', "nominal_length", "nominal_width")
                            ->distinct()
                            ->availableFor($user)
                            ->where("product", $preparedFormData["product"])
                            ->where("material", $preparedFormData["material"])
                            ->where("grade", $preparedFormData["grade"])
                            ->where("surface", SurfaceEnums::NONE->value)
                            ->where("nominal_units", MeasurementUnitEnums::MILLIMETERS->value)
                            ->where("nominal_length",$preparedFormData["nominal_length"])
                            ->where("nominal_width",$preparedFormData["nominal_width"])
                            ->get();
                    }

                    $rawMaterialQuote = RawMaterialQuote::find($formData["data"]["id"]);
                    $rawMaterialQuote->product_category = $preparedFormData["product"];
                    $rawMaterialQuote->general_product_matches = serialize($generalProductMatches->toArray());
                    $rawMaterialQuote->save();

                    /**
                     * Create 'Pieces'
                     */
                    $project = Project::findOrFail($formData["data"]["project_id"]);
                    Piece::create([
                        'project_id' => $project->id,
                        "raw_material_quote_id" => $rawMaterialQuote->id,
                        "product" => $preparedFormData["product"],
                        "material" => $preparedFormData["material"],
                        "grade" => $preparedFormData["grade"],
                        "surface" => SurfaceEnums::NONE->value,
                        "nominal_units" => MeasurementUnitEnums::MILLIMETERS->value,
                        "nesting_algo" => $preparedFormData['nesting_algo'],
                        "nominal_length" => $preparedFormData['nominal_length'],
                        "nominal_width" => $preparedFormData['nominal_width'],
                        "nominal_height" => $preparedFormData['nominal_height'],
                        "actual_length" => $formData["data"]["length_required"],
                        "actual_width" => $formData["data"]["width_required"],
                        "actual_qty" => $formData["data"]["sub_qty"]
                    ]);
                }
            }

            return back();
        }
    }

    private function preparedFormDataSingleProduct(array $formData): array
    {
        /**
         * Single purpose: prepare data array for single product
         */

        return [
            "description" => $formData["data"]["description"],
            "product" => $formData["selected"]["product"] === "Other"
                ? $formData["selected_other"]["product"]
                : $formData["selected"]["product"],
            "material" => $formData["selected"]["material"] === "Other"
                ? $formData["selected_other"]["material"]
                : $formData["selected"]["material"],
            "grade" => $formData["selected"]["grade"] === "Other"
                ? $formData["selected_other"]["grade"]
                : $formData["selected"]["grade"],
            "nominal_length" => $formData["selected"]["nominal_length"],
            "nominal_width" => $formData["selected"]["nominal_width"],
            "nominal_height" => $formData["selected"]["nominal_height"],
            //"nominal_units" => $formData["selected"]["quantify"],
            "nesting_algo" => $formData["selected"]["nesting_algo"],
            "purchasable_length_1" => $formData["selected"]["purchasable_length_1"],
            "purchasable_length_2" => $formData["selected"]["purchasable_length_2"],
            "purchasable_length_3" => $formData["selected"]["purchasable_length_3"],
            "purchasable_width_1" => $formData["selected"]["purchasable_width_1"],
            "purchasable_width_2" => $formData["selected"]["purchasable_width_2"],
            "purchasable_width_3" => $formData["selected"]["purchasable_width_3"],
        ];
    }
    private function productVariations(array $preparedFormData, Business $business): array
    {
        /**
         * Prepare product variations.
         * 1) METERAGE: lengths are the variations
         * 2) AREA: length AND width combinations are the variations
         * 3) BUNDLE: no variations
         */

        $productService = new ProductService();
        $variations = [];

        /*
         * METERAGE
         */
        if($preparedFormData["nesting_algo"] === NestingEnums::METERAGE->value){
            if($preparedFormData["purchasable_length_1"]){
                $variations[] = [
                    "nominal_length" => $preparedFormData["purchasable_length_1"],
                    "nominal_width" => $preparedFormData["nominal_width"] ?? null,
                    "nominal_height" => $preparedFormData["nominal_height"] ?? null,
                    "pack_size_1" => 1, //Can purchase 1
                    "pack_size_2" => null,
                    "pack_size_3" => null,
                ];
            }
            if($preparedFormData["purchasable_length_2"]){
                $variations[] = [
                    "nominal_length" => $preparedFormData["purchasable_length_2"],
                    "nominal_width" => $preparedFormData["nominal_width"] ?? null,
                    "nominal_height" => $preparedFormData["nominal_height"] ?? null,
                    "pack_size_1" => 1, //Can purchase 1
                    "pack_size_2" => null,
                    "pack_size_3" => null,
                ];
            }
            if($preparedFormData["purchasable_length_3"]){
                $variations[] = [
                    "nominal_length" => $preparedFormData["purchasable_length_3"],
                    "nominal_width" => $preparedFormData["nominal_width"] ?? null,
                    "nominal_height" => $preparedFormData["nominal_height"] ?? null,
                    "pack_size_1" => 1, //Can purchase 1
                    "pack_size_2" => null,
                    "pack_size_3" => null,
                ];
            }
        }
        /*
         * AREA
         */
        if($preparedFormData["nesting_algo"] === NestingEnums::AREA->value){
            if($preparedFormData["purchasable_length_1"] && $preparedFormData["purchasable_width_1"]){
                $variations[] = [
                    "nominal_length" => $preparedFormData["purchasable_length_1"],
                    "nominal_width" => $preparedFormData["purchasable_width_1"],
                    "nominal_height" => $preparedFormData["nominal_height"],
                    "pack_size_1" => 1, //Can purchase 1
                    "pack_size_2" => null,
                    "pack_size_3" => null,
                ];
            }
            if($preparedFormData["purchasable_length_2"] && $preparedFormData["purchasable_width_2"]){
                $variations[] = [
                    "nominal_length" => $preparedFormData["purchasable_length_2"],
                    "nominal_width" => $preparedFormData["purchasable_width_2"],
                    "nominal_height" => $preparedFormData["nominal_height"],
                    "pack_size_1" => 1, //Can purchase 1
                    "pack_size_2" => null,
                    "pack_size_3" => null,
                ];
            }
            if($preparedFormData["purchasable_length_3"] && $preparedFormData["purchasable_width_3"]){
                $variations[] = [
                    "nominal_length" => $preparedFormData["purchasable_length_3"],
                    "nominal_width" => $preparedFormData["purchasable_width_3"],
                    "nominal_height" => $preparedFormData["nominal_height"],
                    "pack_size_1" => 1, //Can purchase 1
                    "pack_size_2" => null,
                    "pack_size_3" => null,
                ];
            }
        }
        /*
         * BUNDLE
         */
        if($preparedFormData["nesting_algo"] === NestingEnums::BUNDLE->value){
            $variations[] = [
                "nominal_length" => $preparedFormData["nominal_length"],
                "nominal_width" => $preparedFormData["nominal_width"],
                "nominal_height" => $preparedFormData["nominal_height"],
                "pack_size_1" => $preparedFormData["purchasable_length_1"],
                "pack_size_2" => $preparedFormData["purchasable_length_2"],
                "pack_size_3" => $preparedFormData["purchasable_length_3"],
            ];
        }

        $results = [];
        foreach($variations as $variation){
            $results[] = [
                "description" => $preparedFormData["description"],
                "product" => $preparedFormData["product"],
                "material" => $preparedFormData["material"],
                "grade" => $preparedFormData["grade"],
                "surface" => SurfaceEnums::NONE->value, //todo this is ok?,
                "nominal_units" => MeasurementUnitEnums::MILLIMETERS->value,
                "nesting_algo" => $preparedFormData["nesting_algo"],
                "certificates" => $productService->getCertificateFromProductCategory($preparedFormData["product"]),
                "nominal_length" => $variation["nominal_length"],
                "nominal_width" => $variation["nominal_width"],
                "nominal_height" => $variation["nominal_height"],
                "pack_size_1" => $variation["pack_size_1"],
                "pack_size_2" => $variation["pack_size_2"],
                "pack_size_3" => $variation["pack_size_3"],
                "kg_per_m" => 0, //todo can get this from somewhere?
                "baseline_unit_rate" => null, //todo get quoted price
                'business_id' => $business->id,
                "deprecated" => false,
            ];
        }

        return $results;
    }
}
