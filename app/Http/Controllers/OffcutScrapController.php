<?php

namespace App\Http\Controllers;

use App\Enums\OffcutRemovalEnums;
use App\Http\Requests\ScrapOffcutsRequest;
use App\Models\Offcut;
use App\Services\OffcutCleanout;
use Illuminate\Http\RedirectResponse;

class OffcutScrapController extends Controller
{
    /**
     * Weigh in the offcuts somebody has picked off the quarterly cleanout list.
     *
     * The list is re-derived here rather than trusted from the page. Two reasons, and the second is
     * the one that matters: a cleanout page sits open for a while, and in that time a nest can
     * consume one of these offcuts, a colleague can remove it by hand, or - the case worth
     * protecting against - the piece can stop being a candidate at all. So whatever ids arrive are
     * intersected with what the business's rack says today, and anything that has fallen off the
     * list is left exactly where it is and reported.
     *
     * Anyone in the business may, on the same grounds as OffcutRemoveController: the steel is shared
     * and the person who nested the batch is rarely the person standing at the rack. Who did it goes
     * on each row.
     */
    public function __invoke(ScrapOffcutsRequest $request, OffcutCleanout $cleanout): RedirectResponse
    {
        $business = $this->businessOf($request);

        $requested = $request->offcutIds();

        /** @var array<int, Offcut> $scrappable */
        $scrappable = $cleanout->candidates($business)
            ->pluck('offcut')
            ->filter(fn (Offcut $offcut): bool => in_array($offcut->id, $requested, true))
            ->all();

        /*
         * Nothing survived the intersection. Almost always a page that has been open a while - the
         * nest ate them, or somebody else cleared the rack first - so it reads as news rather than
         * as an error.
         */
        if (count($scrappable) === 0) {
            return back()->with(
                'warning',
                'None of those offcuts is on the cleanout list any more. They have been used, '
                .'removed, or are no longer old enough to propose. The list below is current.',
            );
        }

        $note = $request->note();
        $scrapped = [];

        foreach ($scrappable as $offcut) {
            $offcut->removeFromInventory($request->user(), OffcutRemovalEnums::SCRAPPED, $note);

            $scrapped[] = $offcut->unique_mark;
        }

        $count = count($scrapped);
        $missed = count($requested) - $count;

        $message = $count === 1
            ? 'Offcut "'.$scrapped[0].'" has been scrapped and is out of inventory.'
            : $count.' offcuts have been scrapped and are out of inventory ('.implode(', ', $scrapped).').';

        /*
         * Said out loud rather than swallowed. Silently scrapping fewer rows than were ticked is how
         * somebody comes back a week later certain they cleared the rack.
         */
        if ($missed > 0) {
            $message .= ' '.$missed.' more '.($missed === 1 ? 'was' : 'were')
                .' left alone - no longer on the cleanout list.';
        }

        //Reversible on the Removed tab, and worth saying: the row is flagged, never deleted
        $message .= ' Put one back from the Removed tab if that was wrong.';

        return back()->with('success', $message);
    }
}
