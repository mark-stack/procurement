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
                /*
                 * A quote that went to the merchant, or an order that went with it, is not this
                 * action's to delete however little material is left attached to it. It is a record of
                 * something somebody outside the business was told, and the goods receipt may be
                 * against it - the same line Actions/Batch/DeleteBatchesWithoutPieces draws around the
                 * batch underneath. The rows that reach here are ones whose material was deletable, so
                 * nothing sent should be among them; this is what keeps a tidy-up from being the thing
                 * that loses a purchase when one is.
                 */
                if ($quote->quote_sent || $quote->order?->order_sent) {
                    continue;
                }

                if ($quote->pieces()->count() === 0) {
                    $quotesForDeleting[] = $quote->id;

                    /*
                     * order() is a hasOne and optional. A quote used to be created inside a
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
