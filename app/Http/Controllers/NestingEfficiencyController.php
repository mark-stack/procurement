<?php

namespace App\Http\Controllers;

use App\Enums\NestingEnums;
use App\Formatters\NestingFormatter;
use App\Models\Batch;
use Illuminate\Http\JsonResponse;

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
     * board's Nesting card makes.
     */
    public function __invoke(): JsonResponse
    {
        $business = auth()->user()->business;
        $nestingFormatter = new NestingFormatter;

        $efficiency = [];

        /*
         * A nest at a time. nested_state is a longtext holding the whole nest - every bar, offcut and
         * cut on the batch - so loading even the live ones in one go is the heaviest read on this page.
         * lazyById keeps exactly one of them in memory.
         */
        Batch::query()
            ->active()
            ->select(['id', 'nested_state'])
            ->whereIn('user_id', $business->users()->select('id'))
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

        return response()->json([
            'efficiency' => $efficiency,
        ]);
    }
}
