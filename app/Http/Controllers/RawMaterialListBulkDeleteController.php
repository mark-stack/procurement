<?php

namespace App\Http\Controllers;

use App\Models\Piece;
use App\Models\RawMaterialQuote;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RawMaterialListBulkDeleteController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request): RedirectResponse
    {
        /**
         * Delete imported material list and derived PIECE objects
         */
        $ids = $request->selectedRawMaterialQuoteIds;

        //PIECE objects
        Piece::query()
            ->whereIn("raw_material_quote_id",$ids)
            ->delete();

        //Material list
        RawMaterialQuote::query()
            ->whereIn("id",$ids)
            ->delete();

        return back();
    }
}
