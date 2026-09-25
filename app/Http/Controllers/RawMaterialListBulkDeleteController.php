<?php

namespace App\Http\Controllers;

use App\Actions\Batch\DeleteBatchesWithoutPieces;
use App\Actions\Quote\DeleteQuotesWithoutPieces;
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
        $validated = $request->validate([
            'selectedRawMaterialQuoteIds' => ['present', 'array'],
            'selectedRawMaterialQuoteIds.*' => ['integer'],
        ]);

        $business = $this->businessOf($request);

        /**
         * Only this business's rows, and only ones not already quoted or ordered.
         * The modal hides the checkbox on those, but the ids arrive in the request
         * body, so both rules have to hold here too.
         */
        $rawMaterialQuotes = RawMaterialQuote::query()
            ->ownedBy($business)
            ->whereIn('id', $validated['selectedRawMaterialQuoteIds'])
            ->with('piece.quotes', 'piece.order')
            ->get()
            ->reject(fn (RawMaterialQuote $rawMaterialQuote) => $rawMaterialQuote->status() !== null);

        if ($rawMaterialQuotes->isEmpty()) {
            return back();
        }

        $ids = $rawMaterialQuotes->pluck('id')->all();

        //Detach pieces from quote
        $allAssociatedQuotes = [];
        foreach ($rawMaterialQuotes as $rawMaterialQuote) {
            $piece = $rawMaterialQuote->piece;
            if ($piece) {
                //Associated quotes
                foreach ($piece->quotes as $quote) {
                    $allAssociatedQuotes[] = $quote;
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
