<?php

namespace App\Http\Controllers;

use App\Enums\GradeEnums;
use App\Enums\MaterialEnums;
use App\Enums\MeasurementUnitEnums;
use App\Enums\SurfaceEnums;
use App\Models\Business;
use App\Models\RawMaterialQuote;
use App\Services\NotificationService;
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
        $productService = new NotificationService();

        foreach($request->all() as $item){
            $selectedProduct = $item["options"][$item["selected"]];

            $rawMaterialQuote = RawMaterialQuote::findOrFail($item["data"]["id"]);

            $generalProductMatches = $productService->findGeneralProductMatches(
                $user,
                $selectedProduct["product"],
                MaterialEnums::from($selectedProduct["material"]),
                [GradeEnums::from($selectedProduct["grade"])],
                SurfaceEnums::from($selectedProduct["surface"]),
                isset($selectedProduct["nominal_units"]) ? MeasurementUnitEnums::from($selectedProduct["nominal_units"]) : null,
                $selectedProduct["nominal_length"],
                $selectedProduct["nominal_width"],
                $selectedProduct["nominal_height"],
            );

            if($generalProductMatches->count() === 1){
                $rawMaterialQuote->general_product_matches = serialize($generalProductMatches);
                $rawMaterialQuote->save();
            }
        }

        return back();
    }
}
