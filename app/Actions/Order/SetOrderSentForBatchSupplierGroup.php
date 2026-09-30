<?php

namespace App\Actions\Order;

use App\Models\Batch;
use App\Models\Order;
use Illuminate\Database\Eloquent\Collection;
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

    /**
     * The order in this one's supplier group that has already been delivered, if there is one.
     *
     * Marking an order sent un-sends every other order in its group, and that used to include one the
     * steel had already arrived against - leaving a row that is delivered but not sent, which is a
     * state nothing else in the application knows how to read. Batch::newStockOrdersWithCertificates()
     * filters on order_sent, so the mill certificates behind delivered steel dropped off the spec
     * sheet, while Business::offcutsInInventory() keys availability on is_delivered alone and went on
     * offering its offcuts. The proper undo route refuses a delivered order for exactly this reason
     * (OrderUndoSentController); this was the back door into the same thing.
     *
     * Reported rather than skipped. Quietly leaving the delivered order sent would put two sent orders
     * in one supplier group, which is the invariant this action exists to hold.
     */
    public function deliveredSibling(Order $orderedOrder, Batch $batch): ?Order
    {
        $supplierGroup = $orderedOrder->quote?->supplier_category;

        if ($supplierGroup === null) {
            return null;
        }

        /** @var Collection<int, Order> $orders */
        $orders = $batch->orders()
            ->whereKeyNot($orderedOrder->getKey())
            ->where('is_delivered', true)
            ->with('quote:id,supplier_category', 'supplier:id,name')
            ->get();

        return $orders->first(fn (Order $order): bool => $order->quote?->supplier_category === $supplierGroup);
    }
}
