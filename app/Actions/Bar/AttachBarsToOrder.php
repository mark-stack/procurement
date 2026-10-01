<?php

namespace App\Actions\Bar;

use App\Models\Bar;
use App\Models\Batch;
use App\Models\Order;
use App\Services\ProductService;
use Lorisleiva\Actions\Concerns\AsAction;

class AttachBarsToOrder
{
    use AsAction;

    /**
     * Point this batch's bars at the order that is buying them.
     *
     * The same rule AttachPiecesToOrder applies, on the other side of the nest: an order covers one
     * supplier group, and the bars that belong to it are the ones whose product category that group
     * stocks. A batch that needs steel and bolts places two orders, and neither of them bought the
     * other's bars.
     *
     * Run when somebody presses "Sent order" rather than when the nest is saved, because that is when
     * the question has an answer - before then the batch has a cut plan and no supplier. Undone by
     * DetachBarsFromOrder when a sent order is withdrawn.
     *
     * Why the bars need an order at all: it is the only route from a cut to a mill certificate. The
     * certificates hang off the order (material_certificates.order_id), the heat number is written onto
     * the bar at goods receipt, and the cuts hang off the bar - so without this column the chain has a
     * hole in exactly the place somebody asks about.
     */
    public function handle(Batch $batch, Order $orderedOrder): void
    {
        //quote_id is nullable - an order with no quote belongs to no supplier group, so no bar matches
        $supplierGroupOfOrderedOrder = $orderedOrder->quote?->supplier_category;

        if ($supplierGroupOfOrderedOrder === null) {
            return;
        }

        $barIds = $this->barIdsInSupplierGroup($batch, $supplierGroupOfOrderedOrder);

        if ($barIds === []) {
            return;
        }

        /*
         * One statement for the whole set. These rows carry no change log of their own - Bar does not
         * use RecordsChanges - and attaching twenty bars one model at a time to record twenty
         * indistinguishable "order_id was set" events would be noise bought at the price of twenty
         * queries. What a reader wants to know is which order the bar is on, and the column says so.
         */
        Bar::query()->whereIn('id', $barIds)->update(['order_id' => $orderedOrder->id]);
    }

    /**
     * The ids of this batch's bars whose product category belongs to the given supplier group.
     *
     * Resolved one category at a time rather than one bar at a time. A batch of two hundred bars holds
     * a handful of distinct categories, and ProductService has to build an implementation for each
     * question asked - which is what made the equivalent loop in AttachPiecesToOrder a per-row cost.
     *
     * @return list<int>
     */
    private function barIdsInSupplierGroup(Batch $batch, string $supplierGroup): array
    {
        $productService = new ProductService;

        //product_category => does this group stock it
        $groupStocksCategory = [];
        $barIds = [];

        foreach ($batch->bars()->get(['id', 'product_category']) as $bar) {
            $category = (string) $bar->product_category;

            if (! array_key_exists($category, $groupStocksCategory)) {
                $implementation = $productService->getImplementationFromProductCategory($category);

                /*
                 * A category the catalogue no longer implements. It cannot be placed in a supplier
                 * group, so it is left unattached rather than guessed at - an unattached bar reports as
                 * having no order, which is true, where a guessed one would hang a certificate trail off
                 * a merchant who never supplied it.
                 */
                $groupStocksCategory[$category] = $implementation !== null
                    && $implementation->config()['supplierGroup']->value === $supplierGroup;
            }

            if ($groupStocksCategory[$category]) {
                $barIds[] = (int) $bar->id;
            }
        }

        return $barIds;
    }
}
