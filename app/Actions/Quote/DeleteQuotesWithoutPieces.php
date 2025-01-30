<?php

namespace App\Actions\Quote;

use App\Models\Order;
use App\Models\Quote;
use Lorisleiva\Actions\Concerns\AsAction;

class DeleteQuotesWithoutPieces
{
    use AsAction;

    public function handle(array $quotes): void
    {
        $quotesForDeleting = [];
        $ordersForDeleting = [];

        if (count($quotes) > 0) {
            foreach ($quotes as $quote) {
                if ($quote->pieces()->count() === 0) {
                    $quotesForDeleting[] = $quote->id;
                    $ordersForDeleting[] = $quote->order->id;
                }
            }
        }

        //Delete orders (bit like cascading associated)
        if (count($ordersForDeleting) > 0) {
            Order::query()
                ->whereIn('id', array_unique($ordersForDeleting))
                ->delete();
        }

        //Delete quotes
        if (count($quotesForDeleting) > 0) {
            Quote::query()
                ->whereIn('id', array_unique($quotesForDeleting))
                ->delete();
        }
    }
}
