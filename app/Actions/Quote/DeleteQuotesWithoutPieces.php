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

                    /*
                     * order() is a hasOne and optional. QuoteFormatter creates the quote inside a
                     * transaction and its order immediately after but OUTSIDE it, so a request that died
                     * in between leaves a quote carrying none - and reading ->id off that was a fatal in
                     * the middle of a bulk delete, after the pieces had already gone.
                     */
                    if ($quote->order) {
                        $ordersForDeleting[] = $quote->order->id;
                    }
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
