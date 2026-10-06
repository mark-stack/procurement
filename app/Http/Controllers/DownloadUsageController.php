<?php

namespace App\Http\Controllers;

use App\Formatters\NestingFormatter;
use App\Models\Business;
use App\Sandbox\Sandbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class DownloadUsageController extends Controller
{
    /**
     * How well everything waiting would nest, which is the open batch card's "% usage".
     *
     * There is no saved nest to read - the batch does not exist yet - so the only way to this figure is
     * to run the nesting algorithm over every unbatched piece in the business, which is the slowest
     * read in the application.
     *
     * It is also fetched automatically, by the one screen the application has, every time that screen
     * draws: on login, on every redraw after a press, on every return to the tab. So the answer is
     * cached, and the card's sublabel is the only thing that reads it - the nest somebody acts on comes
     * from SuggestedNestingController, which is never cached and always runs.
     *
     * NOTHING HERE IS A MEASUREMENT, and that is why this one is allowed to be recomputed. It is a
     * forecast of work nobody has committed to: there is no batch, so there are no settings retained
     * against one, and today's offcuts and today's price book are the right things to answer with
     * because that is what the nest would be cut from if it were approved now. A figure that is
     * retained is a figure about something that happened - see App\Services\BatchMeasurements, which
     * writes one down the moment this forecast becomes a nest.
     */
    public function __invoke(Request $request): JsonResponse
    {
        //Formatter
        $nestingFormatter = new NestingFormatter();

        $user = auth()->user();
        $business = $user->business;

        $piecesReadyForBatching = $nestingFormatter->piecesReadyForBatching($business);

        /*
         * Ten minutes, and a key that changes the moment the work waiting does.
         *
         * The fingerprint covers what the nest is built out of: the pieces themselves, and the offcuts
         * in the rack it would cut them from. Edit a material list, upload another, nest a batch,
         * scrap an offcut, and the next request is a miss - which is every change somebody makes and
         * then immediately looks at this number to see the effect of.
         *
         * The TTL is for the rest: the price book, the master materials and the business's own kerf
         * and stock lengths all feed the algorithm and none of them are in the key. Those move rarely
         * and never on this page, so a figure up to ten minutes behind one of them is the trade for
         * not running the heaviest read in the application on every draw of the home screen.
         */
        $usageData = Cache::remember(
            $this->cacheKey($business, $piecesReadyForBatching),
            now()->addMinutes(10),
            function () use ($nestingFormatter, $piecesReadyForBatching, $business) {
                $lettersProjectArray = $nestingFormatter->getLetterProjectArray($piecesReadyForBatching);
                $piecesNested = $nestingFormatter->piecesNested($piecesReadyForBatching, $lettersProjectArray, $business);

                return $nestingFormatter->usageStats($piecesNested);
            },
        );

        return response()->json([
            'usageData' => $usageData,
        ]);
    }

    /**
     * What the answer depends on, said in one string.
     *
     * The stamps rather than the row counts: a material list edited in place changes neither the
     * number of pieces waiting nor the highest id among them, and it changes the nest.
     *
     * Keyed by whose test mode this is as well. Both reads below run under the sandbox global scope so
     * the two modes see different rows anyway - but an empty rack and an empty open batch fingerprint
     * the same in either, and a business's real figure must not be served into somebody's sandbox on
     * the strength of that. See App\Models\Concerns\BelongsToSandbox.
     *
     * @param  Collection<int, \App\Models\Piece>  $piecesReadyForBatching
     */
    private function cacheKey(Business $business, Collection $piecesReadyForBatching): string
    {
        $pieces = $piecesReadyForBatching
            ->map(fn ($piece) => $piece->id.':'.$piece->updated_at?->getTimestamp())
            ->implode('|');

        $offcuts = $business->availableOffcuts()
            ->get(['id', 'updated_at'])
            ->map(fn ($offcut) => $offcut->id.':'.$offcut->updated_at?->getTimestamp())
            ->implode('|');

        return 'pending-nest-usage:'
            .$business->id
            .':'.(Sandbox::ownerId() ?? 'live')
            .':'.md5($pieces.'#'.$offcuts);
    }
}
