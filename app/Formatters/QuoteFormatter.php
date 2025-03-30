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

        //4B) get list of required supplier groups for this batch (note business might not have all suppliers added yet)
        $requiredSupplierGroups = [];
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
                    $quote = Quote::query()
                        ->where('batch_id', $batch->id)
                        ->where('supplier_id', $supplier->id)
                        ->where('supplier_category', $supplierGroup)
                        ->first();

                    //Need to Create
                    if (! $quote) {
                        $quote = Quote::create(
                            [
                                'batch_id' => $batch->id,
                                'supplier_id' => $supplier->id,
                                'supplier_category' => $supplierGroup,
                                'user_id' => $user->id,
                                'supplier_quote_reference' => null,
                                'quote_sent' => false,
                            ]
                        );

                        //Attach pieces to quote
                        AttachPiecesToQuote::run($batch, $quote);
                    }

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

                    $rows[] = [
                        'info' => [
                            'supplier' => $supplier,
                            'order_sent' => $order->order_sent,
                            "is_delivered" => $order->is_delivered,
                        ],
                        'formQuoteUpdate' => [
                            'batch_id' => $batch->id,
                            'quote_id' => $quote->id,
                            'quote_sent' => $quote->quote_sent,
                            'supplier_quote_reference' => $quote->supplier_quote_reference,
                            'quoted_price' => $quote->quoted_price,
                            'quoted_lead_time' => $quote->quoted_lead_time,
                            "canMarkQuoteAsSent" => (new PrerequisiteConditions())->markQuoteAsSent(
                                $user,
                                $quote,
                            ),
                            "canUndoMarkQuoteAsSent" => (new PrerequisiteConditions())->undoMarkQuoteAsSent(
                                $user,
                                $quote,
                            ),
                        ],
                        'formOrderUpdate' => [
                            'batch_id' => $batch->id,
                            'order_id' => $order->id,
                            'supplier_group' => $supplierGroup,
                            'ordered_quote_id' => $orderOfSupplierGroup ? $orderOfSupplierGroup->quote->id : null,
                            'purchase_order_number' => $orderOfSupplierGroup ? $orderOfSupplierGroup->purchase_order_number : null,
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
                        'qtyQuotes' => $batch->quotes()
                            ->where('supplier_category', $supplierGroup)
                            ->where('quote_sent', true)
                            ->count(),
                        'order' => $orderOfSupplierGroup,
                        'purchaseOrderNumber' => $orderOfSupplierGroup ? $orderOfSupplierGroup->purchase_order_number : null,
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
