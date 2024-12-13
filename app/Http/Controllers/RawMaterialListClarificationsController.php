<?php

namespace App\Http\Controllers;

use App\Enums\GradeEnums;
use App\Enums\MaterialEnums;
use App\Enums\MeasurementUnitEnums;
use App\Enums\SurfaceEnums;
use App\Models\Business;
use App\Models\RawMaterialQuote;
use App\Services\ProductService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RawMaterialListClarificationsController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, Business $business): RedirectResponse
    {
        $user = auth()->user();
        $productService = new ProductService();

        foreach($request->all() as $item){
            $selectedProduct = $item["options"][$item["selected"]];
//            "product" => "PFC"
//            "material" => "STEEL"
//            "grade" => "GR300"
//            "surface" => "NONE"
//            "measurement_unit" => "METERS"
//            "size" => "100"

            $rawMaterialQuote = RawMaterialQuote::findOrFail($item["data"]["id"]);

            $generalProductMatches = $productService->findGeneralProductMatches(
                $user,
                $selectedProduct["product"],
                MaterialEnums::from($selectedProduct["material"]),
                [GradeEnums::from($selectedProduct["grade"])],
                SurfaceEnums::from($selectedProduct["surface"]),
                isset($selectedProduct["measurement_unit"]) ? MeasurementUnitEnums::from($selectedProduct["measurement_unit"]) : null,
                $selectedProduct["size"],
                $selectedProduct["length"] ?? null,
            );

            if($generalProductMatches->count() === 1){
                $rawMaterialQuote->general_product_matches = serialize($generalProductMatches);
                $rawMaterialQuote->save();
            }
        }

        return back();
    }
}
