<?php

namespace App\Actions\Piece;

use App\Models\Batch;
use App\Models\Order;
use Lorisleiva\Actions\Concerns\AsAction;

class DetachPiecesFromOrder
{
    use AsAction;

    public function handle(Batch $batch, Order $orderedOrder): void
    {
        /**
         * Detach PIECE from ORDER.
         */
        //quote_id is nullable - an order with no quote belongs to no supplier group, so no piece matches
        $supplierGroupOfOrderedOrder = $orderedOrder->quote?->supplier_category;

        if ($supplierGroupOfOrderedOrder === null) {
            return;
        }

        foreach ($batch->pieces as $piece) {
            //Pieces from batch belonging to this supplier group
            if ($piece->supplierGroup() === $supplierGroupOfOrderedOrder) {
                $piece->order_id = null;
                $piece->save();
            }
        }
    }
}
