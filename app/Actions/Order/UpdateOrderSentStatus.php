<?php
namespace App\Actions\Order;

use App\Models\Order;
use Lorisleiva\Actions\Concerns\AsAction;

class UpdateOrderSentStatus
{
    use AsAction;

    public function handle(Order $order, bool $status): void
    {
        $order->order_sent = $status;
        $order->save();
    }
}
