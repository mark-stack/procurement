<?php

namespace App\Http\Controllers;

use App\Enums\SurfaceEnums;
use App\Models\Business;
use App\Models\Piece;
use App\Models\Product;
use App\Models\Project;
use App\Models\RawMaterialQuote;
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
        $validator = Validator::make([], []);
        $validationErrors = 0;
        foreach($request->all() as $index => $row){
            $product = $row["selected"]["product"];
            $material = $row["selected"]["material"];
            $grade = $row["selected"]["grade"];
            $size = $row["selected"]["size"];
            $measurementUnit = $row["selected"]["quantify"];
            $nestingType = $row["selected"]["nesting_algo"];
            $purchasable_length_1 = $row["selected"]["purchasable_length_1"];
            $purchasable_length_2 = $row["selected"]["purchasable_length_2"];
            $purchasable_length_3 = $row["selected"]["purchasable_length_3"];
            $purchasable_width_1 = $row["selected"]["purchasable_width_1"];
            $purchasable_width_2 = $row["selected"]["purchasable_width_2"];
            $purchasable_width_3 = $row["selected"]["purchasable_width_3"];

            //product
            if($product){
                if($product === "Other" && !$row['selected_other']['product']){
                    $validationErrors++;
                    $validator->errors()->add($index."-product", 'product');
                }
            }
            else{
                $validationErrors++;
                $validator->errors()->add($index."-product", 'product');
            }

            //material
            if($material){
                if($material === "Other" && !$row['selected_other']['material']){
                    $validationErrors++;
                    $validator->errors()->add($index."-material", 'material');
                }
            }
            else{
                $validationErrors++;
                $validator->errors()->add($index."-material", 'material');
            }

            //Grade
            if($grade){
                if($grade === "Other" && !$row['selected_other']['grade']){
                    $validationErrors++;
                    $validator->errors()->add($index."-grade", 'grade');
                }
            }
            else{
                $validationErrors++;
                $validator->errors()->add($index."-grade", 'grade');
            }

            //Size
            if(!$size){
                $validationErrors++;
                $validator->errors()->add($index."-size", 'size');
            }

            //Nesting & measurement units
            if($nestingType){
                /**
                 * Quantify (measurement units)
                 */
                /*
                 * NONE (no minimum volume)
                 *  - Measurement units required: FALSE
                 *  - Size required: FALSE
                 *  - purchasable_length_1: FALSE
                 *  - purchasable_width_1: FALSE
                 */
                if($nestingType === "NONE"){
                    //No actions
                }
                /*
                 * BUNDLE
                 * - Measurement units required: FALSE
                 * - Size required: TRUE
                 * - purchasable_length_1: TRUE
                 * - purchasable_width_1: FALSE
                 */
                if($nestingType === "BUNDLE"){
                    //Size required: TRUE
                    if(!$size){
                        $validationErrors++;
                        $validator->errors()->add($index."-size", 'size');
                    }
                    //purchasable_length_1: TRUE
                    if(!$purchasable_length_1){
                        $validationErrors++;
                        $validator->errors()->add($index."-purchasable_length_1", 'purchasable_length_1');
                    }
                }
                /*
                 * METERAGE
                 * - Measurement units required: TRUE
                 * - Size required: TRUE
                 * - purchasable_length_1: TRUE
                 * - purchasable_width_1: FALSE
                 */
                if($nestingType === "METERAGE"){
                    //Measurement units required: TRUE
                    if(!$measurementUnit){
                        $validationErrors++;
                        $validator->errors()->add($index."-quantify", 'quantify');
                    }
                    //Size required: TRUE
                    if(!$size){
                        $validationErrors++;
                        $validator->errors()->add($index."-size", 'size');
                    }
                    //purchasable_length_1: TRUE
                    if(!$purchasable_length_1){
                        $validationErrors++;
                        $validator->errors()->add($index."-purchasable_length_1", 'purchasable_length_1');
                    }
                }
                /*
                 * AREA
                 * - Measurement units required: TRUE
                 * - Size required: FALSE
                 * - purchasable_length_1: TRUE
                 * - purchasable_width_1: TRUE
                 */
                if($nestingType === "AREA"){
                    //Measurement units required: TRUE
                    if(!$measurementUnit){
                        $validationErrors++;
                        $validator->errors()->add($index."-quantify", 'quantify');
                    }
                    //purchasable_length_1: TRUE
                    if(!$purchasable_length_1){
                        $validationErrors++;
                        $validator->errors()->add($index."-purchasable_length_1", 'purchasable_length_1');
                    }
                    //purchasable_width_1: TRUE
                    if(!$purchasable_width_1){
                        $validationErrors++;
                        $validator->errors()->add($index."-purchasable_width_1", 'purchasable_width_1');
                    }
                }
            }
            else{
                $validationErrors++;
                $validator->errors()->add($index."-nesting_algo", 'nesting_algo');
            }
        }

        //has errors
        if($validationErrors > 0){
            throw new ValidationException($validator);
        }
        else{
            $user = auth()->user();
            $productService = new ProductService();

            foreach($request->all() as $item){

                //  "selected" => array:6 [▼
                //    "product" => "LVL"
                //    "material" => "ALLOY"
                //    "grade" => "NONE"
                //    "size" => 76
                //    "quantify" => "FEET"
                //    "suppliers" => "Other"
                //  ]
                //  "selected_other" => array:6 [▼
                //    "product" => null
                //    "material" => null
                //    "grade" => null
                //    "surface" => null
                //    "quantify" => null
                //    "suppliers" => null
                //  ]
                //  "data" => array:15 [▼
                //    "id" => 487
                //    "created_at" => "2024-12-05T20:33:30.000000Z"
                //    "updated_at" => "2024-12-05T20:33:30.000000Z"
                //    "csv_index" => 27
                //    "description" => "Steel Beams (I-Beams)"
                //    "product_category" => "UB"
                //    "material" => null
                //    "measurement_unit" => "METERS"
                //    "length_required" => "12"
                //    "width_required" => "1"
                //    "sub_qty" => "2"
                //    "unit_rate" => "50"
                //    "project_id" => 2
                //    "general_product_matches" => "a:0:{}"
                //    "product" => null
                //  ]
                //  "subOption" => array:4 [▼
                //    "product" => "all"
                //    "material" => "all"
                //    "grade" => "all"
                //    "suppliers" => "all"
                //  ]

                $product = $item["selected"]["product"] === "Other"
                    ? $item["selected_other"]["product"]
                    : $item["selected"]["product"];
                $material = $item["selected"]["material"] === "Other"
                    ? $item["selected_other"]["material"]
                    : $item["selected"]["material"];
                $grade = $item["selected"]["grade"] === "Other"
                    ? $item["selected_other"]["grade"]
                    : $item["selected"]["grade"];
                $size = $item["selected"]["size"];
                $measurementUnit = $item["selected"]["quantify"];
                $nestingAlgo = $item["selected"]["nesting_algo"];
                $purchasable_length_1 = $item["selected"]["purchasable_length_1"];
                $purchasable_length_2 = $item["selected"]["purchasable_length_2"];
                $purchasable_length_3 = $item["selected"]["purchasable_length_3"];
                $purchasable_width_1 = $item["selected"]["purchasable_width_1"];
                $purchasable_width_2 = $item["selected"]["purchasable_width_2"];
                $purchasable_width_3 = $item["selected"]["purchasable_width_3"];

                $productVariations = [
                    [
                        "purchasable_length" => $purchasable_length_1,
                        "purchasable_width" => $purchasable_width_1,
                    ],
                ];
                if($purchasable_length_2 && $purchasable_width_2){
                    $productVariations[] = [
                        "purchasable_length" => $purchasable_length_2,
                        "purchasable_width" => $purchasable_width_2,
                    ];
                }
                if($purchasable_length_3 && $purchasable_width_3){
                    $productVariations[] = [
                        "purchasable_length" => $purchasable_length_3,
                        "purchasable_width" => $purchasable_width_3,
                    ];
                }

                foreach($productVariations as $productVariation){
                    /**
                     * "Length" means purchasable qty.
                     * For meterage this means length like meters. e.g 9 meters
                     * For area this means length
                     * For bundles this means pack qty
                     */
                    $length = $productVariation["purchasable_length"] ?? null;

                    //Create item
                    $productObject = Product::create([
                        "spreadsheet_id" => null,
                        "description" => $item["data"]["description"],
                        "product" => $product,
                        "material" => $material,
                        "grade" => $grade,
                        "surface" => SurfaceEnums::NONE->value,
                        "measurement_unit" => $measurementUnit,
                        "nesting_algo" => $nestingAlgo,
                        "size" => $size,
                        "length" => $length,
                        "width" => $productVariation["purchasable_width"] ?? null,
                        "kg_per_m" => 0,
                        "baseline_unit_rate" => 0, //todo get quoted price
                        'business_id' => $business->id,
                        "deprecated" => false,
                    ]);

                    /**
                     * Create 'Pieces'
                     */
                    $project = Project::findOrFail($item["data"]["project_id"]);
                    $piece = Piece::create([
                        'project_id' => $project->id,
                        "product" => $productObject->product,
                        "material" => $productObject->material,
                        "grade" => $productObject->grade,
                        "surface" => $productObject->surface,
                        "measurement_unit" => $productObject->measurement_unit,
                        "nesting_algo" => $productObject->nesting_algo,
                        "size" => $productObject->size,
                        "actual_length" => $item["data"]["length_required"],
                        "actual_width" => $item["data"]["width_required"],
                        "actual_qty" => $item["data"]["sub_qty"]
                    ]);
                }

                /**
                 * General product matches
                 */
                $generalProductMatches = Product::select('product', 'material', 'grade', 'surface', 'measurement_unit', 'size')
                    ->distinct()
                    ->availableFor($user)
                    ->where("product", $product)
                    ->where("material", $material)
                    ->where("grade", $grade)
                    ->where("surface", SurfaceEnums::NONE->value)
                    ->where("measurement_unit", $measurementUnit)
                    ->where("size",$size)
                    ->get();
                $rawMaterialQuote = RawMaterialQuote::find($item["data"]["id"]);
                $rawMaterialQuote->general_product_matches = serialize($generalProductMatches->toArray());
                $rawMaterialQuote->save();
            }

            return back();
        }
    }
}
