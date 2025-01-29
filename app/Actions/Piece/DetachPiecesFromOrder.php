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
         * Detach PIECE from BATCH.
         */
        $supplierGroupOfOrderedOrder = $orderedOrder->quote->supplier_category;

        foreach($batch->pieces as $piece){
            //Pieces from batch belonging to this supplier group
            if($piece->supplierGroup() === $supplierGroupOfOrderedOrder){
                $piece->order_id = null;
                $piece->save();
            }
        }
    }
}
