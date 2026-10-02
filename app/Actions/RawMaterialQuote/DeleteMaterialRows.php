<?php

namespace App\Actions\RawMaterialQuote;

use App\Actions\Batch\DeleteBatchesWithoutPieces;
use App\Actions\Quote\DeleteQuotesWithoutPieces;
use App\Models\Business;
use App\Models\Piece;
use App\Models\RawMaterialQuote;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsAction;

class DeleteMaterialRows
{
    use AsAction;

    /**
     * Take material rows off a project, and everything downstream of them with it.
     *
     * This was the body of RawMaterialListBulkDeleteController, which was the only thing that knew
     * how: deleting a material row is not a one-liner, because a row that has reached a piece is
     * referenced by pieces.raw_material_quote_id, and that piece may be on a quote, that quote may
     * carry an order, and the pieces may be the last ones on a batch. A bare delete() answers with a
     * 500 off the first of those.
     *
     * It moved here when deleting a whole uploaded file became a second way in - see
     * MaterialListFile::deleteWithRows. Two callers each doing four steps in the right order is how
     * one of them ends up doing three.
     *
     * Deliberately NOT where the "is this row still deletable" rule lives. Both callers answer that
     * first and differently: the bulk endpoint silently drops the rows it may not take, because the
     * modal has already hidden their checkboxes, while a file refuses outright and names them. By the
     * time anything reaches here the decision has been made.
     *
     * @param  Collection<int, RawMaterialQuote>  $rawMaterialQuotes
     */
    public function handle(Collection $rawMaterialQuotes, Business $business): void
    {
        if ($rawMaterialQuotes->isEmpty()) {
            return;
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

        //Delete any left-over batches with no materials (and associated order approvals). Scoped to
        //this business - it used to walk every batch in the table
        DeleteBatchesWithoutPieces::run($business);
    }
}
