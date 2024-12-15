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
        /**
         * Single purpose: save the non-price book product as user-custom product
         */
        $productService = new ProductService();
        $rows = $request->all();
        $validation = $productService->validationUserCustom($rows);

        //Has errors
        if($validation['validationErrors'] > 0){
            throw new ValidationException($validation['validator']);
        }
        else{
            $user = auth()->user();

            foreach($rows as $item){
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
                    Product::create([
                        "spreadsheet_id" => null,
                        "description" => $item["data"]["description"],
                        "product" => $product,
                        "material" => $material,
                        "grade" => $grade,
                        "surface" => SurfaceEnums::NONE->value,
                        "nominal_units" => $measurementUnit,
                        "nesting_algo" => $nestingAlgo,
                        "size" => $size,
                        "length" => $length,
                        "width" => $productVariation["purchasable_width"] ?? null,
                        "kg_per_m" => 0,
                        "baseline_unit_rate" => 0, //todo get quoted price
                        'business_id' => $business->id,
                        "deprecated" => false,
                    ]);
                }

                /**
                 * General product matches
                 */
                $generalProductMatches = Product::select('product', 'material', 'grade', 'surface', 'nominal_units', 'size')
                    ->distinct()
                    ->availableFor($user)
                    ->where("product", $product)
                    ->where("material", $material)
                    ->where("grade", $grade)
                    ->where("surface", SurfaceEnums::NONE->value)
                    ->where("nominal_units", $measurementUnit)
                    ->where("size",$size)
                    ->get();
                $rawMaterialQuote = RawMaterialQuote::find($item["data"]["id"]);
                $rawMaterialQuote->general_product_matches = serialize($generalProductMatches->toArray());
                $rawMaterialQuote->save();

                /**
                 * Create 'Pieces'
                 */
                $project = Project::findOrFail($item["data"]["project_id"]);
                Piece::create([
                    'project_id' => $project->id,
                    "raw_material_quote_id" => $rawMaterialQuote->id,
                    "product" => $product,
                    "material" => $material,
                    "grade" => $grade,
                    "surface" => SurfaceEnums::NONE->value,
                    "nominal_units" => $measurementUnit,
                    "nesting_algo" => $nestingAlgo,
                    "size" => $size,
                    "actual_length" => $item["data"]["length_required"],
                    "actual_width" => $item["data"]["width_required"],
                    "actual_qty" => $item["data"]["sub_qty"]
                ]);
            }

            return back();
        }
    }
}
