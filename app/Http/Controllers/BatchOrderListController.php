<?php

namespace App\Http\Controllers;

use App\Formatters\NestingFormatter;
use App\Models\Batch;
use App\Models\MaterialCertificate;
use App\Models\Order;
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
         * no certificate to chase and neither does timber, and a block asking for one it will never
         * get reads as paperwork somebody has lost.
         */
        $certificateProductCategories = $nestingFormatter->getCertificateProductLabels();

        $groups = [];

        foreach ($grouped['assigned'] as $supplierGroup => $pieces) {
            $orders = $ordersByGroup[$supplierGroup] ?? new EloquentCollection;
            //First wins where a group somehow has two sent orders, as on the quotes/orders card
            $order = $orders->firstWhere('order_sent', true);

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
                 */
                'ordered' => $order !== null,
                'purchaseOrderNumber' => $order?->purchase_order_number,
                /*
                 * Whether this group's steel comes with a mill certificate at all, and the ones that
                 * have arrived. Asked of every order in the group rather than the sent one: a
                 * certificate can be attached before the order is placed, and a block that holds the
                 * file but shows "No mill cert" would send somebody chasing the merchant for it.
                 */
                'certificated' => $productCategories
                    ->intersect($certificateProductCategories)
                    ->isNotEmpty(),
                'certificates' => $this->certificates($orders),
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
     * What the goods receipt against this group's order says.
     *
     * The same record the quotes/orders modal draws - see QuoteFormatter::goodsReceiptState, and
     * deliberately under the same key names, because it is the same delivery read on a second screen
     * and two spellings of received_at would be two things to keep in step. What is missing is
     * everything to do with writing one: this modal is for reading, and nothing here books steel in.
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
