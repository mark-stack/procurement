<?php

namespace App\Http\Controllers;

use App\Http\Requests\RemoveOffcutRequest;
use App\Models\Offcut;
use Illuminate\Http\RedirectResponse;

class OffcutRemoveController extends Controller
{
    /**
     * Take one offcut out of inventory by hand.
     *
     * Anyone in the business may. The steel is shared, the person who nested the batch that produced
     * an offcut is rarely the person who walks past its empty rack, and an inventory only the original
     * project manager can correct is an inventory that stays wrong. Who did it is recorded on the row
     * instead - see Offcut::removeFromInventory.
     */
    public function __invoke(RemoveOffcutRequest $request, int $offcut): RedirectResponse
    {
        $business = $this->businessOf($request);

        /*
         * Resolved out of the business's own inventory rather than by route model binding, which
         * carries no scoping at all. This one query answers all four questions worth asking: is it
         * this business's steel, is it in the sandbox the user is currently in (batches carry that),
         * has a nest already consumed it, and has somebody else removed it in the meantime.
         */
        $offcut = $business->availableOffcuts()->whereKey($offcut)->first();

        abort_if($offcut === null, 404);

        /** @var Offcut $offcut */
        $offcut->removeFromInventory($request->user(), $request->reason(), $request->note());

        return back()->with(
            'success',
            'Offcut "'.$offcut->unique_mark.'" ('.$offcut->product_derived_label.', '
            .$offcut->length.'mm) is no longer in inventory, and no nest will use it.',
        );
    }
}
