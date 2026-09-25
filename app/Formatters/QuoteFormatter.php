<?php

namespace App\Formatters;

use App\Actions\Piece\AttachPiecesToQuote;
use App\Models\Batch;
use App\Models\Business;
use App\Models\Order;
use App\Models\Quote;
use App\Models\User;
use App\PrerequisiteConditions\PrerequisiteConditions;
use App\Services\BatchService;
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
                        'formUndoOrderSent' => [
                            'order_id' => $order->id,
                        ],
                        'formDelivered' => [
                            'order_id' => $order->id,
                        ],
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
                'totalQuotesQty' => $batch->quotes()->count(),
                'sentQuotesQty' => $batch->quotes()->where('quote_sent', true)->count(),
                'totalOrdersQty' => $batchService->totalOrdersQty($batch),
                'sentOrdersQty' => $batch->orders()->where('order_sent', true)->count(),
                "deliveredQty" => $batch->orders()->where('is_delivered', true)->count(),
                'projectManagerApprovalMessage' => $batchService->projectManagerApprovalMessage($batch),
            ],
            'supplierGroupCards' => $supplierGroupCards,
        ];
    }
}
