<?php

namespace App\Http\Controllers;

use App\Enums\OffcutRemovalEnums;
use App\Http\Resources\OffcutResource;
use App\Models\Offcut;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OffcutController extends Controller
{
    /**
     * How many removals the page carries.
     *
     * Removed offcuts are never deleted, so this list only ever grows - a yard that loses one offcut a
     * week would be shipping five years of them down the wire to render a table nobody scrolls. What
     * the page is actually for is undoing a removal, and the removal being undone is almost always
     * the last one. The count beside it says how many there are in total, so the cap never reads as
     * "that is all of them".
     */
    private const REMOVED_SHOWN = 50;

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): Response
    {
        $business = $this->businessOf($request);

        $offcuts = $business->availableOffcuts()
            //bar for the payload, sourceBatch (+ its user/business) so the resource reads it without a
            //query per row
            ->with(['bar', 'sourceBatch.user.business'])
            ->get();

        /*
         * The chain each offcut was cut down from, resolved for the whole page at once.
         * The resource reads the generation and the source marks off it; left to walk per row it would
         * cost one query per offcut per generation.
         */
        $offcuts = Offcut::loadAncestry($offcuts);

        /*
         * The steel somebody took, cut up off-system, damaged or cannot find. Shown rather than
         * hidden: a removal is a claim about the yard made in one click from a list where two rows can
         * differ only by their mark, so it has to be visible, attributable and reversible.
         */
        $removed = $business->removedOffcuts()
            ->with(['bar', 'sourceBatch.user.business', 'removedBy'])
            ->limit(self::REMOVED_SHOWN)
            ->get();

        $removed = Offcut::loadAncestry($removed);

        return Inertia::render('OffcutsIndex', [
            'offcuts' => OffcutResource::collection($offcuts),
            'removedOffcuts' => OffcutResource::collection($removed),
            //What the list above is a window onto, so the page can say when it is not all of them
            'removedTotal' => $business->removedOffcuts()->count(),
            //The picker's options, worded in the one place the wording lives
            'removalReasons' => OffcutRemovalEnums::options(),
        ]);
    }
}
