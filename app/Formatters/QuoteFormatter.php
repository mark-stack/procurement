<?php

namespace App\Formatters;

use App\Actions\Piece\AttachPiecesToQuote;
use App\Enums\GoodsReceiptNonconformanceEnums;
use App\Models\Bar;
use App\Models\Batch;
use App\Models\Business;
use App\Models\Order;
use App\Models\Project;
use App\Models\Quote;
use App\Models\User;
use App\PrerequisiteConditions\PrerequisiteConditions;
use App\Services\BatchService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;

class QuoteFormatter
{
    public function quotesData(Business $business, Batch $batch, User $user): array
    {
        /**
         * "Quotes and orders"
         * 1) Assign a letter to each project. A, B, C, etc
         * 2) Get all nested pieces
         * 3) Group nested pieces by nesting algorithm. e.g "meterage"
         * 4) get list of supplier categories available to the business
         * 5) filter out categories not features in the nesting list
         */

        //Formatter
        $batchService = new BatchService;
        $nestingFormatter = new NestingFormatter();
        $supplierService = new SupplierFormatter;
        $prerequisiteConditions = new PrerequisiteConditions();

        $supplierGroupCards = [];

        //1) Assign a letter to each project. A, B, C, etc
        $lettersProjectArray = $nestingFormatter->getLetterProjectArray($batch->pieces);

        //2) Get all nested pieces
        $piecesNested = $nestingFormatter->piecesNested($batch->pieces, $lettersProjectArray, $business);

        //3) Group nested pieces by nesting algorithm. e.g "meterage"
        $piecesGroupedBySupplierGroup = $nestingFormatter->piecesGroupedBySupplierGroup($piecesNested, $business);

        /*
         * 4A) get list of supplier categories available to the business
         * Note this might not have full coverage of this batch
         */
        $supplierGroupsAvailableToBusiness = $supplierService->supplierGroupsAvailableToBusiness($business);

        /*
         * Sent quotes per supplier group, in one query rather than one per group. Provisioning below
         * only ever inserts quote_sent = false rows, so this stays correct for the whole render.
         */
        $sentQuoteCountsByGroup = $batch->quotes()
            ->where('quote_sent', true)
            ->selectRaw('supplier_category, count(*) as aggregate')
            ->groupBy('supplier_category')
            ->pluck('aggregate', 'supplier_category');

        //4B) get list of required supplier groups for this batch (note business might not have all suppliers added yet)
        foreach ($piecesGroupedBySupplierGroup['assigned'] as $supplierGroup => $includedProducts) {
            //Business has a supplier for this supplier group
            if (isset($supplierGroupsAvailableToBusiness[$supplierGroup])) {

                $includedProductsString = $supplierGroupsAvailableToBusiness[$supplierGroup]['string'];

                $orderOfSupplierGroup = $batch->orders()
                    ->whereRelation('quote', 'supplier_category', '=', $supplierGroup)
                    ->where('order_sent', true)
                    ->first();

                $rows = [];
                $suppliers = $supplierService->suppliersForSupplierGroup($supplierGroup, $business);

                foreach ($suppliers as $supplier) {
                    /*
                     * Quotes and orders are provisioned lazily, on a GET, the first time a supplier
                     * appears on this page. That used to be a lookup followed by a create, so two
                     * concurrent loads could both miss and both insert. firstOrCreate plus the unique
                     * indexes from 2026_09_25_150000 makes the loser of the race read the winner's row.
                     */
                    $quote = DB::transaction(function () use ($batch, $supplier, $supplierGroup, $user) {
                        $quote = Quote::firstOrCreate(
                            [
                                'batch_id' => $batch->id,
                                'supplier_id' => $supplier->id,
                                'supplier_category' => $supplierGroup,
                            ],
                            [
                                'user_id' => $user->id,
                                'supplier_quote_reference' => null,
                                'quote_sent' => false,
                            ]
                        );

                        //Attach pieces to quote - only the load that actually created it
                        if ($quote->wasRecentlyCreated) {
                            AttachPiecesToQuote::run($batch, $quote);
                        }

                        return $quote;
                    });

                    $order = Order::firstOrCreate(
                        [
                            'quote_id' => $quote->id,
                        ],
                        [
                            'user_id' => $quote->user_id,
                            'batch_id' => $quote->batch_id,
                            'supplier_id' => $quote->supplier_id,
                            'order_sent' => false,
                        ]
                    );

                    /*
                     * Hand the prerequisites the batch and order we already have. Walking $quote->batch
                     * reloaded the batch once per quote, and each fresh instance re-ran the project
                     * lookup its conditions memoise - four times per row.
                     */
                    $quote->setRelation('batch', $batch);
                    $quote->setRelation('order', $order);

                    //An order created a moment ago has nothing attached, so don't go and ask
                    if ($order->wasRecentlyCreated) {
                        $order->setRelation('materialCertificates', new EloquentCollection);
                    }

                    $rows[] = [
                        'info' => [
                            'supplier' => $supplier,
                            'order_sent' => $order->order_sent,
                            "is_delivered" => $order->is_delivered,
                        ],
                        /*
                         * Only what the page reads. The price / lead time / quote reference columns
                         * were commented out of the template, and shipping their values as form state
                         * meant every quote save wrote them back over themselves.
                         */
                        'formQuoteUpdate' => [
                            'quote_id' => $quote->id,
                            'quote_sent' => $quote->quote_sent,
                            "canMarkQuoteAsSent" => $prerequisiteConditions->markQuoteAsSent(
                                $user,
                                $quote,
                            ),
                            "canUndoMarkQuoteAsSent" => $prerequisiteConditions->undoMarkQuoteAsSent(
                                $user,
                                $quote,
                            ),
                        ],
                        /*
                         * Every field here is this row's own order. purchase_order_number used to be
                         * read off the group's sent order, so saving certs on a row wrote another
                         * order's PO number onto it - or blanked it when nothing was sent yet.
                         */
                        'formOrderUpdate' => [
                            'batch_id' => $batch->id,
                            'order_id' => $order->id,
                            'purchase_order_number' => $order->purchase_order_number,
                            "material_cert_numbers" => $order->material_cert_numbers,
                        ],
                        /*
                         * The other half of the certs cell. These are rows of their own rather than a
                         * column on the order, so they are not form state - the modal uploads and
                         * deletes them one at a time and re-reads this list.
                         */
                        'materialCertificateFiles' => $order->materialCertificates
                            ->map(fn ($certificate) => [
                                'id' => $certificate->id,
                                'filename' => $certificate->original_filename,
                                'size_bytes' => $certificate->size_bytes,
                            ])
                            ->values()
                            ->all(),
                        'formUndoOrderSent' => [
                            'order_id' => $order->id,
                        ],
                        'formDelivered' => [
                            'order_id' => $order->id,
                        ],
                        /*
                         * The goods receipt: what the gate recorded, and - for an order that has been
                         * placed but not yet booked in - the bars whose heat numbers it can record.
                         */
                        'goodsReceipt' => $this->goodsReceiptState($order),
                    ];
                }

                $supplierGroupCards[$supplierGroup] = [
                    'hasSuppliersForThisGroup' => true,
                    'info' => [
                        'supplierGroup' => $supplierGroup,
                        'batchGroup' => $piecesGroupedBySupplierGroup['assigned'][$supplierGroup],
                        'includedProducts' => $includedProductsString,
                        'qtyQuotes' => (int) ($sentQuoteCountsByGroup[$supplierGroup] ?? 0),
                        'order' => $orderOfSupplierGroup,
                        'purchaseOrderNumber' => $orderOfSupplierGroup?->purchase_order_number,
                    ],
                    'rows' => $rows,
                ];
            }
            //NO suppliers for this supplier group
            else {
                /*
                 * Included products string
                 */
                $productCategories = collect($includedProducts)->pluck('product_category')->toArray();
                $includedProductsString = implode(', ', array_unique($productCategories));

                $supplierGroupCards[$supplierGroup] = [
                    'hasSuppliersForThisGroup' => false,
                    'info' => [
                        'supplierGroup' => $supplierGroup,
                        'includedProducts' => $includedProductsString,
                    ],
                ];
            }
        }

        return [
            'info' => [
                /*
                 * The jobs this batch is buying for, named under the modal's heading.
                 *
                 * A batch is the whole Nesting column swept into one nest, so "Quotes / Orders" on
                 * its own named nothing: the suppliers and the sections are visible, and which of
                 * your colleagues' work is in the cart was not. It matters most on the screen where
                 * the order actually goes out, and more again since the fabrication deadline sweep
                 * started creating batches nobody pressed a button for - the email that brings you
                 * here names one project, and this is where you find out what came with it.
                 *
                 * projectSummaries() rather than projects(): id and name is all that is drawn, and
                 * the other one eager loads the rawMaterialQuotes/piece/quote/order tree that
                 * ProjectResource needs.
                 */
                'projects' => $batch->projectSummaries()
                    ->map(fn (Project $project) => ['id' => $project->id, 'name' => $project->name])
                    ->values()
                    ->all(),
                'totalQuotesQty' => $batch->quotes()->count(),
                'sentQuotesQty' => $batch->quotes()->where('quote_sent', true)->count(),
                'totalOrdersQty' => $batchService->totalOrdersQty($batch),
                'sentOrdersQty' => $batch->orders()->where('order_sent', true)->count(),
                "deliveredQty" => $batch->orders()->where('is_delivered', true)->count(),
                /*
                 * Deliveries with no receipt behind them.
                 *
                 * Every order marked delivered before the receipt columns existed is one of these, and
                 * so is any booked in by a route that skipped the form. Counted and shown rather than
                 * left to be discovered: an auditor asking "show me the goods receipt for this order"
                 * and being told the column is simply null is the worst moment to find out.
                 */
                'deliveredWithoutReceiptQty' => $batch->orders()
                    ->where('is_delivered', true)
                    ->whereNull('received_at')
                    ->count(),
                'projectManagerApprovalMessage' => $batchService->projectManagerApprovalMessage($batch),
                /*
                 * Sent once for the whole modal rather than on every row - the reasons are the same
                 * seven whichever delivery is being booked in.
                 */
                'receiptNonconformanceOptions' => GoodsReceiptNonconformanceEnums::options(),
            ],
            'supplierGroupCards' => $supplierGroupCards,
        ];
    }

    /**
     * What this order's goods receipt says, and what it still needs.
     *
     * Three states, and the page draws a different thing for each: not sent (nothing to book in), sent
     * and not received (the form, with this order's bars to put heat numbers against), and received
     * (the record, read-only - a receipt is not a draft, so there is no edit).
     *
     * The bars are only read for an order that has been placed and not yet received, which is the only
     * state the form is drawn in. One supplier group has at most one sent order, so this is at most one
     * extra query per group rather than one per supplier row.
     *
     * @return array<string, mixed>
     */
    private function goodsReceiptState(Order $order): array
    {
        $received = $order->hasReceiptRecord();

        $state = [
            'order_id' => $order->id,
            'canReceive' => $order->order_sent && ! $order->is_delivered,
            'received' => $received,
            //True, false, or null for "booked in, and nobody answered the checks"
            'accepted' => $order->receiptAccepted(),
            /*
             * Delivered with no receipt behind it. The flag on its own is what this application
             * recorded for years, so this is not an error state - it is an honest "arrived, unverified",
             * and the page says so rather than drawing an empty receipt.
             */
            'deliveredWithoutReceipt' => $order->is_delivered && ! $received,
            'bars' => [],
        ];

        if ($received) {
            $state += [
                'received_at' => $order->received_at?->toDateTimeString(),
                'received_by' => $order->receivedBy?->name,
                'docket_number' => $order->delivery_docket_number,
                'quantity_verified' => $order->quantity_verified,
                'grade_verified' => $order->grade_verified,
                'nonconformance' => $order->receiptNonconformance()?->label(),
                'note' => $order->receipt_note,
            ];
        }

        if ($state['canReceive']) {
            $state['bars'] = $order->bars()
                ->orderBy('id')
                ->get(['id', 'product_derived_label', 'length', 'heat_number'])
                ->map(fn (Bar $bar) => [
                    'id' => $bar->id,
                    'label' => $bar->product_derived_label,
                    'length' => $bar->length,
                    'heat_number' => $bar->heat_number,
                ])
                ->values()
                ->all();
        }

        return $state;
    }
}
