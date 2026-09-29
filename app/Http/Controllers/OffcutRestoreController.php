<?php

namespace App\Http\Controllers;

use App\Models\Offcut;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class OffcutRestoreController extends Controller
{
    /**
     * Put a removed offcut back into inventory - the removal that was a mistake, or the offcut
     * that turned up behind the rack after all.
     *
     * The way back matters as much as the way out: removal is a one-click judgement made from a list
     * where two rows can differ only by their mark, so the wrong row will be picked, and without this
     * the only fix is the database.
     */
    public function __invoke(Request $request, int $offcut): RedirectResponse
    {
        $business = $this->businessOf($request);

        //Scoped the same way the removal was, on the other side of the flag - see OffcutRemoveController
        $offcut = $business->removedOffcuts()->whereKey($offcut)->first();

        abort_if($offcut === null, 404);

        /** @var Offcut $offcut */
        $offcut->restoreToInventory();

        return back()->with(
            'success',
            'Offcut "'.$offcut->unique_mark.'" is back in inventory, and nests may use it again.',
        );
    }
}
