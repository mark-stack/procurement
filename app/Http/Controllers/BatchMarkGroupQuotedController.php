<?php

namespace App\Http\Controllers;

use App\Formatters\SupplierFormatter;
use App\Models\Batch;
use App\PrerequisiteConditions\PrerequisiteConditions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class BatchMarkGroupQuotedController extends Controller
{
    /**
     * "Quoted" on one block of the order list - this merchant's price has come in.
     *
     * The step before BatchMarkGroupOrderedController's, written the same way and for the same shop:
     * the steel goes out through the quotes screen and the timber is priced over the phone, so the
     * batch is neither all quoted nor not quoted, and the batch-wide "All quoted" on the card menu
     * can only say one of those.
     *
     * It is what the order list offers while the batch is still being priced. Marking every merchant
     * on the batch quoted is the same claim the card's "All quoted" makes, so the card reads QUOTED
     * once the last block is marked - see NestingIndexController::fullyMarkedQuotedBatchIds.
     *
     * It sends nothing and asks nobody for anything. No quote row is written, no merchant is
     * contacted, and the batch does not change column - that is BatchStages' business and it reads
     * sent orders. This writes a name into a list on the batch (see the 2026_10_05_100000 migration),
     * and the order list reads that list alongside the real quotes.
     */
    public function __invoke(Request $request, Batch $batch): RedirectResponse
    {
        Gate::authorize('owned', $batch);

        $user = $request->user();

        abort_if(! (new PrerequisiteConditions)->markBatchGroupQuoted($user, $batch), 403);

        $validated = $request->validate([
            'supplier_group' => ['required', 'string', 'max:191'],
        ]);

        $group = $validated['supplier_group'];

        /*
         * A group this business actually has, the way the ordered mark checks it: the name arrives
         * from the page, and a list of free text on the batch would be a list of typos no block could
         * ever be matched to.
         */
        abort_unless(
            array_key_exists($group, (new SupplierFormatter)->supplierGroups($user->business)),
            422,
        );

        $marked = $batch->quoted_supplier_groups ?? [];

        //Pressing it twice is a second click on a block that had not redrawn yet, not an error
        if (! in_array($group, $marked, true)) {
            $marked[] = $group;

            $batch->update(['quoted_supplier_groups' => array_values($marked)]);
        }

        return back();
    }
}
