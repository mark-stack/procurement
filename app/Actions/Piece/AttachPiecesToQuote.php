<?php

namespace App\Actions\Piece;

use App\Models\Batch;
use App\Models\Quote;
use Lorisleiva\Actions\Concerns\AsAction;

class AttachPiecesToQuote
{
    use AsAction;

    public function handle(Batch $batch, Quote $quote): void
    {
        /**
         * Attach PIECE to QUOTE (is a single supplier group like "steel merchant")
         */
        $supplierGroup = $quote->supplier_category;

        //Pieces in supplier group
        foreach ($batch->pieces as $piece) {
            //Pieces from batch belonging to this supplier group
            if ($piece->supplierGroup() === $supplierGroup) {
                $piece->quotes()->attach($quote);
            }
        }
    }
}
