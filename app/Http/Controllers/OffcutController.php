<?php

namespace App\Http\Controllers;

use App\Enums\OffcutRemovalEnums;
use App\Http\Resources\OffcutResource;
use App\Models\Offcut;
use App\Services\OffcutCleanout;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
    public function index(Request $request, OffcutCleanout $cleanout): Response
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

            /*
             * The dead stock: steel past its shelf life that is also too short to pay for its own
             * keep. Computed on every render rather than cached against the quarterly notice, so the
             * list somebody acts on is the rack as it stands - a nest run this morning may have
             * eaten half of it.
             */
            'cleanout' => $this->cleanoutRows($cleanout->candidates($business)),
            'cleanoutShelfLifeDays' => $cleanout->shelfLifeDays(),

            //Which tab the notification's "Review the rack" button lands on
            'tab' => $request->query('tab') === 'cleanout' ? 'cleanout' : null,
        ]);
    }

    /**
     * The cleanout candidates as the page needs them: each offcut in the same shape as every other
     * list on this page, with the money that makes it a candidate beside it.
     *
     * @param  Collection<int, array<string, mixed>>  $candidates
     * @return array<int, array<string, mixed>>
     */
    private function cleanoutRows(Collection $candidates): array
    {
        if ($candidates->isEmpty()) {
            return [];
        }

        /*
         * The ancestry for the whole tab at once. OffcutResource reads generation() and the source
         * marks off each row, and left to walk per row that is a query per offcut per generation -
         * the same reason the two lists above do it.
         */
        Offcut::loadAncestry($candidates->pluck('offcut'));

        return $candidates->map(fn (array $candidate): array => [
            /*
             * resolve() rather than the resource itself. A JsonResource nested inside a prop
             * serialises under its own "data" wrapper, so the page would be reading
             * cleanout[].offcut.data.unique_mark while the two lists above read offcut.unique_mark.
             */
            'offcut' => (new OffcutResource($candidate['offcut']))->resolve(),
            'age_days' => $candidate['age_days'],
            'length_mm' => $candidate['length_mm'],
            //Null when no offcut of this section ever pays for its keep, whatever its length
            'floor_mm' => $candidate['floor_mm'],
            'kg_per_m' => $candidate['kg_per_m'],
            'kg_per_m_resolved' => $candidate['kg_per_m_resolved'],
            'worth' => $candidate['worth'],
            'keep_cost' => $candidate['keep_cost'],
            'bin_recovers' => $candidate['bin_recovers'],
            'net_drain' => $candidate['net_drain'],
        ])->all();
    }
}
