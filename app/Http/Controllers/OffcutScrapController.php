<?php

namespace App\Http\Controllers;

use App\Enums\OffcutRemovalEnums;
use App\Http\Requests\ScrapOffcutsRequest;
use App\Models\Offcut;
use App\Services\OffcutCleanout;
use App\Services\ScrapLedger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

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
     *
     * Two things are written per offcut, not one. Taking it out of inventory stops the next nest
     * promising steel that is in a skip; the scrap row beside it is the weight and the money, which is
     * the only form in which a cleared rack can be added up, trended, or measured against a yield
     * objective. The removal flag alone says a piece went away and nothing about how much steel that
     * was - see Services\ScrapLedger.
     */
    public function __invoke(
        ScrapOffcutsRequest $request,
        OffcutCleanout $cleanout,
        ScrapLedger $ledger,
    ): RedirectResponse {
        $business = $this->businessOf($request);

        $requested = $request->offcutIds();

        /*
         * The whole candidate row, not just the offcut on it.
         *
         * The money the cleanout worked out - what the piece is worth, what the bin pays for it, and
         * whether the catalogue could price the section at all - is what the page put in front of
         * whoever ticked the box, and it is what Services\ScrapLedger writes down. Taking only the id
         * here and re-pricing the steel on the other side would record a second opinion on a decision
         * already made on the strength of the first.
         *
         * @var array<int, array<string, mixed>> $scrappable
         */
        $scrappable = $cleanout->candidates($business)
            ->filter(fn (array $candidate): bool => in_array($candidate['offcut']->id, $requested, true))
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
        $weight = 0.0;

        /*
         * One transaction. Taking the steel out of inventory and writing down what it weighed are two
         * halves of the same event: a rack cleared with no scrap row behind it is a write-off that
         * never reaches a yield figure, and a scrap row for an offcut still showing as available is a
         * loss counted against material the next nest will go looking for.
         */
        DB::transaction(function () use ($scrappable, $request, $ledger, $note, &$scrapped, &$weight): void {
            foreach ($scrappable as $candidate) {
                /** @var Offcut $offcut */
                $offcut = $candidate['offcut'];

                $offcut->removeFromInventory($request->user(), OffcutRemovalEnums::SCRAPPED, $note);

                /*
                 * The row that makes this a quantity rather than a flag. Without it the only record of
                 * a cleared rack is "this offcut is not here any more", which carries no weight, no
                 * money and nothing to trend a yield objective against.
                 */
                $scrap = $ledger->recordCleanout($candidate, $request->user(), $note);

                $scrapped[] = $offcut->unique_mark;
                $weight += $scrap->weight_kg;
            }
        });

        $count = count($scrapped);
        $missed = count($requested) - $count;

        /*
         * The weight is said out loud because it is the point of the row behind it. "Three offcuts
         * scrapped" is a tidier rack; "three offcuts, 74kg" is a figure that turns up in the scrap
         * report next quarter, and seeing it at the moment of pressing the button is what makes that
         * connection rather than a surprise.
         */
        $weighs = ' ('.number_format($weight).'kg)';

        $message = $count === 1
            ? 'Offcut "'.$scrapped[0].'" has been scrapped'.$weighs.' and is out of inventory.'
            : $count.' offcuts have been scrapped'.$weighs.' and are out of inventory ('
                .implode(', ', $scrapped).').';

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
