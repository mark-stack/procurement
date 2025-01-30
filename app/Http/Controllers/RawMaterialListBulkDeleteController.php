<?php

namespace App\Http\Controllers;

use App\Actions\Batch\DeleteBatchesWithoutPieces;
use App\Actions\Quote\DeleteQuotesWithoutPieces;
use App\Models\Piece;
use App\Models\Quote;
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

        $rawMaterialQuotes = RawMaterialQuote::query()
            ->whereIn('id', $ids)
            ->get();

        //Detach pieces from quote
        $allAssociatedQuotes = [];
        $allAssociatedOrders = [];
        foreach ($rawMaterialQuotes as $rawMaterialQuote) {
            $piece = $rawMaterialQuote->piece;
            if ($piece) {
                //Associated quotes
                foreach ($piece->quotes as $quote) {
                    $allAssociatedQuotes[] = $quote;
                }
                //Associated orders
                if ($piece->order) {
                    $allAssociatedOrders[] = $piece->order;
                }

                $piece->quotes()->detach();
            }
        }

        //Delete pieces
        Piece::query()
            ->whereIn('raw_material_quote_id', $ids)
            ->delete();

        //Delete any left-over quotes without pieces (and associated orders)
        DeleteQuotesWithoutPieces::run($allAssociatedQuotes);

        //Delete Material list
        RawMaterialQuote::query()
            ->whereIn('id', $ids)
            ->delete();

        //Delete any left-over batches with no materials (and associated order approvals)
        DeleteBatchesWithoutPieces::run();

        return back();
    }
}
