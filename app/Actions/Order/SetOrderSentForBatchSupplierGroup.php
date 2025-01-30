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
        $supplierGroupOfOrderedOrder = $orderedOrder->quote->supplier_category;

        foreach ($batch->orders as $order) {
            $matchingSupplierGroup = $order->quote->supplier_category === $supplierGroupOfOrderedOrder;

            //This order
            if ($order->id == $orderedOrder->id) {
                UpdateOrderSentStatus::run($order, true);
            }
            //Other orders
            elseif ($matchingSupplierGroup) {
                UpdateOrderSentStatus::run($order, false);
            }
        }
    }
}
