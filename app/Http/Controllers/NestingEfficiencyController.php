<?php

namespace App\Http\Controllers;

use App\Enums\NestingEnums;
use App\Formatters\NestingFormatter;
use App\Models\Batch;
use App\Models\Business;
use App\Sandbox\Sandbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class NestingEfficiencyController extends Controller
{
    /**
     * How well each of this business's live batches nested, keyed by batch id.
     *
     * Read off the nest saved against the batch rather than by nesting it again - the figure the card
     * shows has to be the figure the batch was actually bought on, and re-running the algorithm would
     * answer with today's offcuts and today's price book instead.
     *
     * Fetched by the Nesting page after it has drawn, not sent with it, because this walks a saved nest
     * per card and each of those is the whole nest of a batch - the cards are worth reading before the
     * percentages land.
     *
     * Live batches only, matching the cards the page draws: a finished batch has no card to put a
     * percentage on, and unpacking its nest to answer nobody is the most expensive thing this page could
     * do - that is a list which only grows. A finished batch's nest is read from /past-projects.
     *
     * The pending card is not in here. It has no saved nest, so its efficiency can only come from
     * running the suggestion, which is what DownloadUsageController answers - the same request the
     * board's Nesting card made.
     */
    public function __invoke(): JsonResponse
    {
        $business = auth()->user()->business;

        /*
         * The ids and the stamps, which are both the answer's cache key and the list to walk.
         *
         * This is the Nesting page's home screen request and it ran in full on every visit - every
         * login, every redraw after a press, every time somebody came back to the tab - decoding the
         * whole nest of every live batch to produce a line of small print under a button. The answer
         * only changes when a nest does, and a nest is written through the model
         * (Actions\Batch\SaveNesting, through CreateBarsAndOffcuts), so updated_at moves with it: a key built from the ids
         * and their stamps is stale exactly never, and is a miss exactly when something was re-nested.
         */
        $live = Batch::query()
            ->active()
            ->select(['id', 'updated_at'])
            ->whereIn('user_id', $business->users()->select('id'))
            ->orderBy('id')
            ->get();

        $key = $this->cacheKey($business, $live->map(
            fn (Batch $batch) => $batch->id.':'.$batch->updated_at?->getTimestamp(),
        )->implode('|'));

        /*
         * A week, which is a backstop rather than a policy: the key changes the moment any of these
         * batches does, so an entry is only ever read back while it is still the right answer, and one
         * nobody asks for again is a row the cache can forget.
         */
        $efficiency = Cache::remember(
            $key,
            now()->addWeek(),
            fn () => $this->efficiencyOf($live->pluck('id')->all()),
        );

        return response()->json([
            'efficiency' => $efficiency,
        ]);
    }

    /**
     * Keyed by business, and by whose test mode this is.
     *
     * The ids above are read under the sandbox global scope, so a user in test mode fingerprints a
     * different set of batches and lands on a different entry anyway - but two empty sets fingerprint
     * alike, and a business's real answer must not be served to somebody's sandbox on the strength of
     * that. See App\Models\Concerns\BelongsToSandbox.
     */
    private function cacheKey(Business $business, string $fingerprint): string
    {
        return 'nesting-efficiency:'
            .$business->id
            .':'.(Sandbox::ownerId() ?? 'live')
            .':'.md5($fingerprint);
    }

    /**
     * The walk itself - a nest at a time.
     *
     * nested_state is a longtext holding the whole nest - every bar, offcut and cut on the batch - so
     * loading even the live ones in one go is the heaviest read on this page. lazyById holds a chunk of
     * them at a time rather than the lot.
     *
     * @param  array<int, int>  $batchIds
     * @return array<int, float|int>
     */
    private function efficiencyOf(array $batchIds): array
    {
        $nestingFormatter = new NestingFormatter;

        $efficiency = [];

        Batch::query()
            ->whereIn('id', $batchIds)
            ->select(['id', 'nested_state'])
            ->lazyById(50)
            ->each(function (Batch $batch) use (&$efficiency, $nestingFormatter) {
                $nest = $batch->nested_state;

                /*
                 * Nothing saved. Legitimate: nested_state is only written by Actions\Batch\SaveNesting,
                 * so a batch from before it existed - or one whose nest could not be read - has none.
                 * Left out rather than reported as 0%, which would read as a terrible nest.
                 */
                if ($nest === []) {
                    return;
                }

                $efficiency[$batch->id] = $nestingFormatter
                    ->usageStats($nest)[NestingEnums::METERAGE->value]['efficiency'] ?? 0;
            });

        return $efficiency;
    }
}
