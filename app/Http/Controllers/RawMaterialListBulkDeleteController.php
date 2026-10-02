<?php

namespace App\Http\Controllers;

use App\Actions\RawMaterialQuote\DeleteMaterialRows;
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

        /**
         * Only your own rows, and only ones not already quoted or ordered. The modal hides the
         * checkbox on both - on a colleague's project it draws no checkboxes at all - but the ids
         * arrive in the request body, so both rules have to hold here too.
         */
        $rawMaterialQuotes = RawMaterialQuote::query()
            ->ownedByUser($request->user())
            ->whereIn('id', $validated['selectedRawMaterialQuoteIds'])
            ->with('piece.quotes', 'piece.order')
            ->get()
            ->reject(fn (RawMaterialQuote $rawMaterialQuote) => $rawMaterialQuote->status() !== null);

        if ($rawMaterialQuotes->isEmpty()) {
            return back();
        }

        /*
         * The four steps that used to be written out here - detaching the pieces from their quotes,
         * deleting the pieces, clearing up the quotes and orders left with nothing in them, and then
         * the emptied batches - are DeleteMaterialRows. They moved there when deleting a whole
         * uploaded file became a second way to take material off a project.
         */
        DeleteMaterialRows::run($rawMaterialQuotes, $this->businessOf($request));

        return back();
    }
}
