<?php

namespace App\Http\Controllers;

use App\Formatters\SupplierFormatter;
use App\Models\Batch;
use App\PrerequisiteConditions\PrerequisiteConditions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class BatchMarkGroupOrderedController extends Controller
{
    /**
     * "Ordered" on one block of the order list - this merchant has been bought from.
     *
     * The batch-wide "All ordered" one merchant at a time, and for the shop in between the two this
     * application has been built for: the steel goes through the quotes screen and the bolts are
     * bought over the counter, so the batch is neither all ordered nor not ordered.
     *
     * It places nothing and sends nothing. No order row is written, no quote is touched, nobody is
     * notified, and the board does not move the batch - its columns are built on orders that really
     * were sent. This writes a name into a list on the batch (see the 2026_10_03_140000 migration),
     * and the order list reads that list alongside the real orders.
     */
    public function __invoke(Request $request, Batch $batch): RedirectResponse
    {
        Gate::authorize('owned', $batch);

        $user = $request->user();

        abort_if(! (new PrerequisiteConditions)->markBatchGroupOrdered($user, $batch), 403);

        $validated = $request->validate([
            'supplier_group' => ['required', 'string', 'max:191'],
        ]);

        $group = $validated['supplier_group'];

        /*
         * A group this business actually has. The name arrives from the page, and a list of free text
         * on the batch would be a list of typos the order list could never match a block to - so it
         * is checked against the same groups the list itself is built from.
         */
        abort_unless(
            array_key_exists($group, (new SupplierFormatter)->supplierGroups($user->business)),
            422,
        );

        $marked = $batch->ordered_supplier_groups ?? [];

        //Pressing it twice is not an error, it is a second click on a menu that had not redrawn yet
        if (! in_array($group, $marked, true)) {
            $marked[] = $group;

            $batch->update(['ordered_supplier_groups' => array_values($marked)]);
        }

        return back();
    }
}
