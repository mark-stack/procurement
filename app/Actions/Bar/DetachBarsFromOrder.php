<?php

namespace App\Actions\Bar;

use App\Models\Bar;
use App\Models\Order;
use Lorisleiva\Actions\Concerns\AsAction;

class DetachBarsFromOrder
{
    use AsAction;

    /**
     * Let go of the bars an order is no longer buying.
     *
     * Addressed by order_id rather than by walking the batch and re-deriving the supplier group, which
     * is how AttachBarsToOrder found them. Two reasons, and the second is the one that matters: this has
     * to release exactly what was attached, and the supplier group is read off the order's quote - a
     * row the undo path does not promise is unchanged. Asking "which bars say they are on this order"
     * cannot miss one and cannot take one that belongs to another order.
     *
     * heat_number is deliberately left alone, and cannot be set on anything this reaches. A heat number
     * is only written at goods receipt (OrderMarkDeliveredController), which only runs on a delivery;
     * and the only caller of this is the undo route, which refuses outright to withdraw an order that
     * has been delivered. So a bar arriving here has no heat number to clear. Clearing one anyway would
     * be a write with no case behind it, on the column the whole traceability chain ends at.
     */
    public function handle(Order $order): void
    {
        Bar::query()
            ->where('order_id', $order->id)
            ->update(['order_id' => null]);
    }
}
