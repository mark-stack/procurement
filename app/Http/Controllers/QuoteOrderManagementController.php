<?php

namespace App\Http\Controllers;

use App\Models\Batch;
use App\Models\Order;
use App\Models\Quote;
use App\Services\BatchService;
use App\Services\NestingService;
use App\Services\SupplierService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class QuoteOrderManagementController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, Batch $batch)
    {
        //Services
        $batchService = new BatchService();
        $nestingService = new NestingService();
        $supplierService = new SupplierService();

        //Prerequisite variables
        $user = auth()->user();
        $business = $user->business;

        /**
         * "Quotes and orders"
         * 1) Assign a letter to each project. A, B, C, etc
         * 2) Get all nested pieces
         * 3) Group nested pieces by nesting algorithm. e.g "meterage"
         * 4) get list of supplier categories available to the business
         * 5) filter out categories not features in the nesting list
         */

        $supplierGroupCards = [];

        //1) Assign a letter to each project. A, B, C, etc
        $lettersProjectArray = $nestingService->getLetterProjectArray($batch->pieces);

        //2) Get all nested pieces
        $piecesNested = $nestingService->piecesNested($batch->pieces,$lettersProjectArray);

        //3) Group nested pieces by nesting algorithm. e.g "meterage"
        $batchGroups = $nestingService->batchGroups($piecesNested, $business);

        //4) get list of supplier categories available to the business
        $supplierGroupsAvailableToBusiness = $supplierService->supplierGroupsAvailableToBusiness($business);

        //5) filter out categories not features in the nesting list
        foreach($supplierGroupsAvailableToBusiness as $supplierGroup => $includedProducts){

            //has pieces for this supplier group
            $batchGroup = $batchGroups["assigned"][$supplierGroup] ?? null;

            if($batchGroup){

                $rows = [];
                $suppliers = $supplierService->suppliersForSupplierGroup($supplierGroup, $business);

                $orderedOrder = $batch->orders()
                    ->where("order_sent",true)
                    ->first();

                foreach($suppliers as $supplier){
                    $quote = Quote::firstOrCreate(
                        [
                            "batch_id" => $batch->id,
                            'supplier_id' => $supplier->id,
                            "supplier_category" => $supplierGroup,
                        ],
                        [
                            'user_id' => $user->id,
                            "supplier_quote_reference" => null,
                            "quote_sent" => false,
                        ]
                    );


                    $order = Order::firstOrCreate(
                        [
                            "quote_id" => $quote->id,
                        ],
                        [
                            "user_id" => $quote->user_id,
                            "batch_id" => $quote->batch_id,
                            "supplier_id" => $quote->supplier_id,
                            "order_sent" => false,
                        ]
                    );

                    $rows[] = [
                        "info" => [
                            "supplier" => $supplier,
                            "order_sent" => $order->order_sent,
                        ],
                        "formQuoteUpdate" => [
                            "batch_id" => $batch->id,
                            "quote_id" => $quote->id,
                            "quote_sent" => $quote->quote_sent,
                            "supplier_quote_reference" => $quote->supplier_quote_reference,
                            "quoted_price" => $quote->quoted_price,
                            "quoted_lead_time" => $quote->quoted_lead_time,
                        ],
                        "formOrderUpdate" => [
                            "batch_id" => $batch->id,
                            "order_id" => $order->id,
                            "supplier_group" => $supplierGroup,
                            "ordered_quote_id" => $orderedOrder ? $orderedOrder->quote->id : null,
                            "purchase_order_number" => $orderedOrder ? $orderedOrder->purchase_order_number : null,
                        ],
                        "formUndoOrderSent" => [
                            "order_id" => $order->id,
                        ],
                    ];
                }

                $supplierGroupCards[$supplierGroup] = [
                    "info" => [
                        "supplierGroup" => $supplierGroup,
                        "batchGroup" => $batchGroup,
                        "includedProducts" => $includedProducts,
                        "qtyQuotes" => $batch->quotes()
                            ->where("supplier_category",$supplierGroup)
                            ->where("quote_sent",true)
                            ->count(),
                        "purchaseOrderNumber" => "123-TEST", //todo
                        "delivered" => true, //todo placeholder
                    ],
                    "rows" => $rows,
                ];
            }
        }

        //Total orders qty
        $orders = $batch->orders;
        $supplierCategories = [];
        foreach($orders as $order){
            $supplierCategories[] = $order->quote->supplier_category;
        }
        $totalOrdersQty = count(array_unique($supplierCategories));

        $quotesAndOrders = [
            "info" => [
                "totalQuotesQty" => $batch->quotes()->count(),
                "sentQuotesQty" => $batch->quotes()->where("quote_sent",true)->count(),
                "totalOrdersQty" => $totalOrdersQty,
                "sentOrdersQty" => $batch->orders()->where("order_sent",true)->count(),
                "projectManagerApprovalMessage" => $batchService->projectManagerApprovalMessage($batch),
            ],
            "supplierGroupCards" => $supplierGroupCards,
        ];

        return Inertia::render('QuoteOrderManagement',[
            "width" => 900,
            "modalSelectedBatchId" => $batch->id,
            "refreshModalQuotes" => false, //todo delete
            "quotesData" => $quotesAndOrders,
        ]);
    }
}
