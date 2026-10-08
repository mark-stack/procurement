<?php

namespace App\Http\Controllers;

use App\Formatters\NestingFormatter;
use App\Models\Batch;
use App\Models\MaterialCertificate;
use App\Models\Order;
use App\PrerequisiteConditions\PrerequisiteConditions;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
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
        $ordersByGroup = $this->ordersBySupplierGroup($batch);

        /*
         * Which product categories come with a mill certificate, off products.certificates - the same
         * flag the BOM's certificate column reads (see DownloadBomController). A fastener group has
         * no certificate to chase, and a block asking for one it will never get reads as paperwork
         * somebody has lost.
         */
        $certificateProductCategories = $nestingFormatter->getCertificateProductLabels();

        /*
         * The merchants on this batch somebody has already said they bought from off the application,
         * and whether this user may say it of another one. Both asked once for the modal rather than
         * per block: the gate is about the batch and the people on it, not about the group.
         */
        $markedGroups = $batch === null ? [] : ($batch->ordered_supplier_groups ?? []);

        /*
         * And the same two questions about the step before it: which merchants have had their price
         * marked in from a block, and whether this user may mark another. Separate from the ordered
         * mark because they are separate presses on separate days - the block offers the quoted one
         * while the batch is still being priced and the ordered one after.
         */
        $quotedGroups = $batch === null ? [] : ($batch->quoted_supplier_groups ?? []);

        /*
         * And the step after both of them: the merchants somebody has watched come off the truck. The
         * mark for a group with no order on the application to book in - the bolts the shop fetched
         * - which is why the block offering it is also the block with no purchase order behind it.
         */
        $deliveredGroups = $batch === null ? [] : ($batch->delivered_supplier_groups ?? []);

        /*
         * Plus the merchants whose price came back through the quotes screen, which is the other way
         * a group is priced. A sent quote rather than a quote row, the way the card's QUOTED pill
         * counts one (NestingIndexController::fullyQuotedBatchIds): a row is minted per supplier the
         * moment anybody opens the quotes screen, long before a price was asked of anybody.
         */
        $sentQuoteGroups = $batch === null
            ? []
            : $batch->quotes()
                ->where('quote_sent', true)
                ->pluck('supplier_category')
                ->filter()
                ->all();

        /*
         * And the same claim made about the whole job rather than one merchant - "All ordered" on the
         * Nesting card. Read here rather than written across the groups when that press lands: it is
         * one fact about the batch, and copying it into a list of names would leave the two to drift
         * apart the moment the batch's material changes.
         */
        $wholeBatchOrdered = $batch?->ordered_at !== null;
        $wholeBatchQuoted = $batch?->quoted_at !== null;
        $wholeBatchDelivered = $batch?->delivered_at !== null;
        $canMarkOrdered = $batch === null
            ? null
            : (new PrerequisiteConditions)->markBatchGroupOrdered(auth()->user(), $batch);
        $canMarkQuoted = $batch === null
            ? null
            : (new PrerequisiteConditions)->markBatchGroupQuoted(auth()->user(), $batch);
        $canMarkDelivered = $batch === null
            ? null
            : (new PrerequisiteConditions)->markBatchGroupDelivered(auth()->user(), $batch);

        /*
         * And the certificates attached to the batch itself rather than to one of its orders, in one
         * query for the modal. Empty for the pending card, which has no batch to hold any.
         */
        $batchCertificates = $batch === null
            ? new EloquentCollection
            : $batch->certificates()->get();

        $groups = [];

        foreach ($grouped['assigned'] as $supplierGroup => $pieces) {
            $orders = $ordersByGroup[$supplierGroup] ?? new EloquentCollection;
            //First wins where a group somehow has two sent orders, as on the quotes/orders card
            $order = $orders->firstWhere('order_sent', true);

            /*
             * Bought, by any of the three routes - see the key below for what they are. Lifted out of
             * the array because the quoted question is asked in terms of it: material nobody could
             * have bought without agreeing a price for it.
             */
            $ordered = $order !== null
                || $wholeBatchOrdered
                || in_array($supplierGroup, $markedGroups, true);

            $productCategories = collect($pieces)
                ->pluck('product_category')
                ->filter()
                ->unique()
                ->sort()
                ->values();

            $groups[] = [
                'supplierGroup' => $supplierGroup,
                //What is in this group on this batch, rather than everything the group could cover
                'includedProducts' => $productCategories->implode(', '),
                /*
                 * Whether this group has been bought, so the list says which merchants are still
                 * waiting on an order. Sent and numbered are two questions: the PO number is a
                 * nullable column somebody types in afterwards, so an order can be placed with no
                 * number against it and the modal has to say "ordered" without inventing one.
                 *
                 * Three ways to be bought, and the block says the same word for all of them: a sent
                 * order through the quotes screen, the mark somebody put on this block for a
                 * merchant they rang (see BatchMarkGroupOrderedController), or the Nesting card's
                 * "All ordered", which is that same claim made about the whole job at once and so
                 * about every merchant on it. The mark is kept separate underneath because only one
                 * of the three has an order behind it to show.
                 */
                'ordered' => $ordered,
                'orderedByMark' => $order === null
                    && ($wholeBatchOrdered || in_array($supplierGroup, $markedGroups, true)),
                'purchaseOrderNumber' => $order?->purchase_order_number,
                /*
                 * And whether its price is in, which is the step the block offers before that one.
                 *
                 * Read the same way as ordered, off the same three kinds of evidence: a quote that
                 * went out and came back through the quotes screen, the mark somebody put on this
                 * block for a merchant they rang (BatchMarkGroupQuotedController), or the card's
                 * "All quoted" said of the whole job at once.
                 *
                 * And a fourth that is not a quote at all: a group that has been bought. Nobody
                 * orders steel without knowing what it costs, so a block reading "Ordered" over a
                 * "Mark as quoted" button would be asking for a step that is behind it - which is
                 * what the batch-wide "All ordered" does to a batch nobody marked quoted first.
                 */
                'quoted' => $ordered
                    || $wholeBatchQuoted
                    || in_array($supplierGroup, $quotedGroups, true)
                    || in_array($supplierGroup, $sentQuoteGroups, true),
                /*
                 * And whether this block may offer that mark. Asked of the same user and batch as
                 * canMarkOrdered, and separately, because the two gates are allowed to differ - see
                 * PrerequisiteConditions::markBatchGroupQuoted.
                 */
                'canMarkQuoted' => $canMarkQuoted,
                /*
                 * And whether this block may offer the mark. False where somebody else's job is on
                 * the batch or it is closed, and null on the pending card, which has no batch to
                 * carry a mark and nothing anybody should be buying from it yet.
                 */
                'canMarkOrdered' => $canMarkOrdered,
                /*
                 * And whether its steel is in the rack, which is the step after that one.
                 *
                 * Three ways again, and they line up with the three ways of being bought: the order
                 * that was placed through the quotes screen has been booked in on a goods receipt,
                 * somebody marked this block (BatchMarkGroupDeliveredController), or the card's "All
                 * delivered" said it of the whole job at once.
                 *
                 * Unlike quoted, nothing is inferred from the step before it. A merchant that has
                 * been bought from is not thereby delivered - that is the whole of what this block is
                 * waiting to be told.
                 */
                'delivered' => ($order !== null && $order->is_delivered)
                    || $wholeBatchDelivered
                    || in_array($supplierGroup, $deliveredGroups, true),
                /*
                 * And whether this block may offer that mark - the batch gate, and one more question
                 * the other two marks do not have to ask.
                 *
                 * A merchant with a real order out is delivered by receiving that order, which
                 * records who checked the steel and against what (OrderMarkDeliveredController). A
                 * mark beside that would be a second answer to "has it arrived" that can disagree
                 * with the first, so the block does not offer one and the route refuses it. What is
                 * left is exactly the merchant this mark is for: the one somebody rang.
                 */
                'canMarkDelivered' => $canMarkDelivered === null
                    ? null
                    : ($canMarkDelivered && $order === null),
                /*
                 * Whether this group's steel comes with a mill certificate at all, and the ones that
                 * have arrived. Asked of every order in the group rather than the sent one: a
                 * certificate can be attached before the order is placed, and a block that holds the
                 * file but shows "No mill cert" would send somebody chasing the merchant for it.
                 *
                 * Both kinds in the one list. A shop that buys through the quotes screen gets the
                 * merchant's PDF against the order; a shop that rings the merchant attaches it to
                 * the batch under this group's name, from the Nesting card (see
                 * BatchCertificateController). They are the same evidence about the same steel, so
                 * the block shows them together rather than making somebody know which screen the
                 * file went in through.
                 */
                'certificated' => $productCategories
                    ->intersect($certificateProductCategories)
                    ->isNotEmpty(),
                'certificates' => [
                    ...$this->certificates($orders),
                    ...$this->batchCertificates($batchCertificates, $supplierGroup),
                ],
                /*
                 * What came off the truck, where this group has been bought and the delivery booked
                 * in. Null for a group nobody has ordered from yet, which the block already says.
                 */
                'goodsReceipt' => $this->goodsReceipt($order),
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
     * The orders of each supplier group on this batch, keyed by group.
     *
     * Which group an order belongs to is a fact about its quote, not the order - the same join the
     * quotes/orders card reads its PO number through, so the two screens cannot disagree about who
     * has been ordered from.
     *
     * Unsent orders are kept, and the caller asks each question of the rows it applies to. Only a
     * sent order is a purchase - opening the quotes/orders modal provisions a row per supplier
     * whether or not anybody buys anything - but a certificate hangs off whichever row it was
     * attached to, placed or not.
     *
     * Empty for the pending card, which has no batch and therefore nothing ordered against it.
     *
     * @return array<string, EloquentCollection<int, Order>>
     */
    private function ordersBySupplierGroup(?Batch $batch): array
    {
        if ($batch === null) {
            return [];
        }

        return $batch->orders()
            ->with(['quote', 'materialCertificates', 'receivedBy'])
            ->get()
            ->filter(fn (Order $order) => $order->quote?->supplier_category !== null)
            ->groupBy(fn (Order $order) => $order->quote->supplier_category)
            ->all();
    }

    /**
     * The mill certificates attached to this group's orders.
     *
     * A download link rather than the file: they live on the private disk, and the only way to read
     * one is the route, which checks the certificate's order belongs to you (see
     * DownloadMaterialCertificateController).
     *
     * @param  EloquentCollection<int, Order>  $orders
     * @return array<int, array<string, mixed>>
     */
    private function certificates(EloquentCollection $orders): array
    {
        return $orders
            ->flatMap(fn (Order $order) => $order->materialCertificates)
            ->map(fn (MaterialCertificate $certificate) => [
                'id' => $certificate->id,
                'filename' => $certificate->original_filename,
                'url' => route('material.certificates.download', $certificate),
            ])
            ->values()
            ->all();
    }

    /**
     * And the ones attached to the batch under this merchant's name.
     *
     * Same shape as the order-side list above, because the block draws one list: whoever is looking
     * for a heat number wants the file, not an account of which screen it arrived through.
     *
     * @param  EloquentCollection<int, MaterialCertificate>  $certificates
     * @return array<int, array<string, mixed>>
     */
    private function batchCertificates(EloquentCollection $certificates, string $supplierGroup): array
    {
        return $certificates
            ->filter(fn (MaterialCertificate $certificate) => $certificate->supplier_group === $supplierGroup)
            ->map(fn (MaterialCertificate $certificate) => [
                'id' => $certificate->id,
                'filename' => $certificate->original_filename,
                'url' => route('material.certificates.download', $certificate),
            ])
            ->values()
            ->all();
    }

    /**
     * What the goods receipt against this group's order says.
     *
     * What is missing is everything to do with writing one: this modal is for reading, and nothing
     * here books steel in. The quotes/orders modal used to draw the same record under the same key
     * names and offer the form that wrote it; it went with the projects board, so this is the only
     * screen a goods receipt is read on.
     *
     * Asked of the sent order alone, unlike the certificates. A receipt is only ever written against a
     * placed order (the panel offers it on order_sent && ! is_delivered), so the row that exists merely
     * because somebody opened the quotes/orders modal has nothing to say about a delivery.
     *
     * Null where the group has not been ordered from, and the block then shows no pill at all: "not
     * received" next to "Not ordered" is the same sentence twice.
     *
     * @return array<string, mixed>|null
     */
    private function goodsReceipt(?Order $order): ?array
    {
        if ($order === null) {
            return null;
        }

        $received = $order->hasReceiptRecord();

        $receipt = [
            'received' => $received,
            //True, false, or null for "booked in, and nobody answered the checks"
            'accepted' => $order->receiptAccepted(),
            /*
             * Ticked as arrived with no receipt behind it, which is what every delivery marked before
             * the receipt columns existed looks like. Not an error state: it reads as "arrived, and
             * nobody recorded a check", it is true, and it cannot be filled in after the fact.
             */
            'deliveredWithoutReceipt' => $order->is_delivered && ! $received,
        ];

        if (! $received) {
            return $receipt;
        }

        return $receipt + [
            'received_at' => $order->received_at?->toDateTimeString(),
            //Nullable, and stays so once the account is gone - the receipt outlives whoever wrote it
            'received_by' => $order->receivedBy?->name,
            'docket_number' => $order->delivery_docket_number,
            'quantity_verified' => $order->quantity_verified,
            'grade_verified' => $order->grade_verified,
            'nonconformance' => $order->receiptNonconformance()?->label(),
            'note' => $order->receipt_note,
        ];
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
