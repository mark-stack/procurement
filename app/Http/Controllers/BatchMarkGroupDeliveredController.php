<?php

namespace App\Http\Controllers;

use App\Formatters\SupplierFormatter;
use App\Models\Batch;
use App\PrerequisiteConditions\PrerequisiteConditions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class BatchMarkGroupDeliveredController extends Controller
{
    /**
     * "Delivered" on one block of the order list - this merchant's steel is in the rack.
     *
     * The step after BatchMarkGroupOrderedController's, written the same way and for the same shop:
     * the steel goes out through the quotes screen and the timber is bought over the phone, so the
     * batch is neither all delivered nor still waiting, and the batch-wide "All delivered" on the
     * card menu can only say one of those. A block that had been bought had nothing left to press.
     *
     * It books in nothing. No goods receipt is written, no order is flagged delivered, nobody is
     * notified - this writes a name into a list on the batch (see the 2026_10_05_110000 migration)
     * and the order list reads that list alongside the real deliveries. Marking every merchant is
     * the same claim the card's "All delivered" makes in one press, and the card reads DELIVERED
     * once the last block is in - see NestingIndexController::fullyMarkedDeliveredBatchIds.
     *
     * What it will not do is speak over a goods receipt. A merchant with a sent order against it is
     * delivered by receiving that order (OrderMarkDeliveredController), which records who checked it
     * and against what; a mark set beside that is a second answer to "has it arrived" that can
     * disagree with the first, which is the thing BatchMarkDeliveredController's gate exists to
     * prevent for the whole job. Refused here rather than quietly ignored, because the block does not
     * offer the press in that case and a post arriving anyway is a stale tab.
     */
    public function __invoke(Request $request, Batch $batch): RedirectResponse
    {
        Gate::authorize('owned', $batch);

        $user = $request->user();

        abort_if(! (new PrerequisiteConditions)->markBatchGroupDelivered($user, $batch), 403);

        $validated = $request->validate([
            'supplier_group' => ['required', 'string', 'max:191'],
        ]);

        $group = $validated['supplier_group'];

        /*
         * A group this business actually has, the way both marks before it check it: the name arrives
         * from the page, and a list of free text on the batch would be a list of typos no block could
         * ever be matched to.
         */
        abort_unless(
            array_key_exists($group, (new SupplierFormatter)->supplierGroups($user->business)),
            422,
        );

        abort_if($this->hasSentOrder($batch, $group), 403);

        $marked = $batch->delivered_supplier_groups ?? [];

        //Pressing it twice is a second click on a block that had not redrawn yet, not an error
        if (! in_array($group, $marked, true)) {
            $marked[] = $group;

            $batch->update(['delivered_supplier_groups' => array_values($marked)]);
        }

        return back();
    }

    /**
     * Whether this merchant has a real order out on this batch.
     *
     * Which group an order belongs to is a fact about its quote rather than the order - the same join
     * BatchOrderListController reads the blocks through, so the modal and this press cannot disagree
     * about which merchant has paperwork behind it.
     *
     * Sent orders alone. A row is minted per supplier the moment anybody opens the quotes screen, and
     * an unsent one is nothing anybody is waiting on a delivery from.
     */
    private function hasSentOrder(Batch $batch, string $supplierGroup): bool
    {
        return $batch->orders()
            ->where('order_sent', true)
            ->whereHas('quote', fn ($query) => $query->where('supplier_category', $supplierGroup))
            ->exists();
    }
}
