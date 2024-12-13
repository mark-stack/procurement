<?php

namespace App\Http\Controllers;

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
        $ids = $request->selectedRawMaterialQuoteIds;
        RawMaterialQuote::query()
            ->whereIn("id",$ids)
            ->delete();

        return back();
    }
}
