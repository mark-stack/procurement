<?php

namespace App\Http\Controllers;

use App\Formatters\NestingFormatter;
use App\Models\Batch;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class BatchOrderListController extends Controller
{
    /**
     * What to buy for a batch, a block per supplier group.
     *
     * The same lists the quotes/orders modal's "Email tables" buttons write into a mail - the stock
     * lengths the nest needs, consolidated and counted - but on screen and for the whole batch at once
     * rather than one supplier at a time.
     *
     * Deliberately NOT DownloadQuotesDataController, which answers a similar-looking question. That
     * one provisions a quote per supplier and an order per quote with firstOrCreate, so asking it for
     * a list to read would mint order rows for a batch nobody has decided to buy yet.
     *
     * With no batch in the url it answers for the batch that does not exist yet: the suggestion for
     * everything on the Nesting column, which is the only way to list stock for a nest that has not
     * been saved against anything.
     */
    public function __invoke(?Batch $batch = null): JsonResponse
    {
        $business = auth()->user()->business;
        $nestingFormatter = new NestingFormatter;

        if ($batch !== null) {
            Gate::authorize('owned', $batch);

            /*
             * The nest the batch was bought on, not a fresh one. Re-nesting here would answer with
             * today's offcuts and today's price book, and the order going to the merchant has to be
             * the one the saved nest asks for. Empty on a batch nested before SaveNesting wrote it.
             */
            $piecesNested = $batch->nested_state;
        } else {
            $piecesReadyForBatching = $nestingFormatter->piecesReadyForBatching($business);

            $piecesNested = $piecesReadyForBatching->isEmpty()
                ? []
                : $nestingFormatter->piecesNested(
                    $piecesReadyForBatching,
                    $nestingFormatter->getLetterProjectArray($piecesReadyForBatching),
                    $business,
                );
        }

        $grouped = $nestingFormatter->piecesGroupedBySupplierGroup($piecesNested, $business);

        $groups = [];

        foreach ($grouped['assigned'] as $supplierGroup => $pieces) {
            $groups[] = [
                'supplierGroup' => $supplierGroup,
                //What is in this group on this batch, rather than everything the group could cover
                'includedProducts' => collect($pieces)
                    ->pluck('product_category')
                    ->filter()
                    ->unique()
                    ->sort()
                    ->implode(', '),
                'batchGroup' => $this->batchGroup($pieces),
            ];
        }

        return response()->json([
            'orderList' => [
                //Which card asked, so a late answer for one batch cannot be drawn into another's modal
                'batch_id' => $batch?->id,
                'groups' => $groups,
            ],
        ]);
    }

    /**
     * The group's pieces, trimmed to what a list is written from.
     *
     * Three fields, which are the three the formatter reads - see Shared/shared.js, where the lines
     * themselves are built so that this modal and the "Email tables" buttons cannot word the same
     * order two different ways. The rest of a nested piece is every bar and cut on it, and none of it
     * belongs in a list of stock to buy.
     *
     * @param  array<int, array<string, mixed>>  $pieces
     * @return array<int, array<string, mixed>>
     */
    private function batchGroup(array $pieces): array
    {
        return collect($pieces)
            ->map(fn (array $piece) => [
                'algo' => $piece['algo'] ?? null,
                'product_derived_label' => $piece['product_derived_label'] ?? null,
                'nested' => [
                    'orderList' => $piece['nested']['orderList'] ?? [],
                ],
            ])
            ->values()
            ->all();
    }
}
