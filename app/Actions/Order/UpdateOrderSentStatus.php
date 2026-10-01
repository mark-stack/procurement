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

        /*
         * The date moves with the flag, in both directions.
         *
         * Sending stamps now(): a fresh timestamp each time, because an order re-sent after an undo was
         * sent at the moment it was re-sent, not at the moment of the attempt that was withdrawn.
         *
         * Un-sending clears it, which is the half worth explaining. Leaving the date behind would put
         * an order_sent_at on a row whose order_sent is false, and this application has been bitten
         * repeatedly by exactly that shape - a delivered order that is not sent, an offcut in inventory
         * carrying a note of why it is not there. Two columns describing one fact have to agree.
         *
         * Nothing is lost by clearing it. record_changes holds the transition - order_sent_at from a
         * date to null, with the user and the time - so "it was sent on the 14th and withdrawn on the
         * 15th" is still answerable, and now answerable with who did both.
         */
        $order->order_sent_at = $status ? now() : null;

        $order->save();
    }
}
