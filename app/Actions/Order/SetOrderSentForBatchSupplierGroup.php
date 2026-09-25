<?php

namespace App\Actions\Order;

use App\Models\Batch;
use App\Models\Order;
use Lorisleiva\Actions\Concerns\AsAction;

class SetOrderSentForBatchSupplierGroup
{
    use AsAction;

    public function handle(Order $orderedOrder, Batch $batch): void
    {
        /**
         * This order: order_sent = true
         * Other orders in this batch supplier group: order_sent = false
         */
        //quote_id is nullable, so an order can carry no supplier group at all
        $supplierGroupOfOrderedOrder = $orderedOrder->quote?->supplier_category;

        //Eager load the quote - this walked one query per order in the batch
        $orders = $batch->orders()->with('quote:id,supplier_category')->get();

        foreach ($orders as $order) {
            //This order
            if ($order->id == $orderedOrder->id) {
                UpdateOrderSentStatus::run($order, true);

                continue;
            }

            //Other orders in the same supplier group
            $matchingSupplierGroup = $supplierGroupOfOrderedOrder !== null
                && $order->quote?->supplier_category === $supplierGroupOfOrderedOrder;

            if ($matchingSupplierGroup) {
                UpdateOrderSentStatus::run($order, false);
            }
        }
    }
}
