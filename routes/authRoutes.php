<?php

use App\Actions\Order\SetOrderSentForBatchSupplierGroup;
use App\Actions\OrderApproval\UpdateOrderApprovalStatus;
use App\Actions\Piece\AttachPiecesToOrder;
use App\Actions\Piece\DetachPiecesFromOrder;
use App\Enums\SupplierGroupEnums;
use App\Http\Controllers\BatchController;
use App\Http\Controllers\BatchNestingController;
use App\Http\Controllers\MarkNotificationStatusController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PricebookController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\QuoteController;
use App\Http\Controllers\QuoteOrderManagementController;
use App\Http\Controllers\RawMaterialListBulkDeleteController;
use App\Http\Controllers\RawMaterialListClarificationsController;
use App\Http\Controllers\RawMaterialListCustomisationsController;
use App\Http\Controllers\RawMaterialQuoteController;
use App\Http\Controllers\SuggestedNestingController;
use App\Http\Controllers\SupplierController;
use App\Http\Middleware\BusinessReadyMiddleware;
use App\Models\Batch;
use App\Models\Order;
use App\Models\Project;
use App\Models\Quote;
use App\Services\BatchService;
use App\Services\NestingService;
use App\Services\ProductService;
use App\Services\SupplierService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;

Route::middleware(['auth','verified'])->group(function () {

    //Onboarding
    Route::get('/onboarding', OnboardingController::class)->name('onboarding');

    //Profile
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    //Dashboard
    Route::get('/dashboard', function () {
        $user = auth()->user();
        $business = $user->business;

        //Onboarding complete
        if($business->admin_setup_complete){
            return redirect()->route("projects.index");
        }
        //Not onboarded yet
        else{
            return redirect()->route("onboarding");
        }
    })->name('dashboard');

    //Notifications
    //Route::post("mark-as-read", NotificationMarkAsReadController::class)->name("notification.mark.as.read");
    Route::post("mark-notification-status", MarkNotificationStatusController::class)->name("mark.notification.status");

    //Onboarding is finalised
    Route::middleware([BusinessReadyMiddleware::class])->group(function () {
        //Projects
        Route::resource('projects', ProjectController::class);

        //Products
        Route::controller(ProductController::class)->group(function () {
            Route::get("/{project}/products",'index')->name("products.index"); //GET	/photos	index	photos.index
            //Route::get('/products/{id}', 'products.show')->name("products.show"); //GET	/photos/{photo}	show	photos.show
            Route::post('/{project}/products', 'store')->name("products.store"); //POST	/photos	store	photos.store
        });
        //GET	/photos/create	create	photos.create
        //GET	/photos/{photo}/edit	edit	photos.edit
        //PUT/PATCH	/photos/{photo}	update	photos.update
        //DELETE	/photos/{photo}	destroy	photos.destroy

        Route::get("download-bom/{project}",function(Request $request, Project $project){

            /**
             * Single purpose: upload, clarify, and display consolidated BOM for a project
             */
            Gate::authorize('owned', $project);

            //Services
            $nestingService = new NestingService();
            $productService = new ProductService();

            //Prerequisite variables
            $user = $project->user;
            $business = $user->business;

            /**
             * Sort the user's material rows into groups:
             * 1) Non-price book (will be user custom product)
             * 2) Price book exact match
             * 3) price book partial match (requires confirmation)
             */
            $materialListRows = [];
            $productCategories = [];
            $partialProductMatches = [];
            $allCertificateProductLabels = $nestingService->getCertificateProductLabels();
            $hasCertificateProducts = []; //todo: get from master_materials
            $requiresCustom = [];

            //Loop user's material rows
            foreach($project->rawMaterialQuotes as $rawMaterialQuote){

                //Append Array
                $nesting_algo = ($rawMaterialQuote->product_category && $nestingService->getNestingLabelsFromProductCategory($rawMaterialQuote->product_category))
                    ? $nestingService->getNestingLabelsFromProductCategory($rawMaterialQuote->product_category)[0]
                    : null;
                $rawMaterialQuote->nesting_algo = $nesting_algo;
                $baseline_unit_rate = $productService->getBaseLineUnitRateFromGeneral($getProductMatchOptions["decodedOption"] ?? null);
                $rawMaterialQuote->baseline_unit_rate = $baseline_unit_rate;
                $rawMaterialQuote->baseline_unit_rate_comparison = $productService->getBaselineUnitRateHighLowComparison($rawMaterialQuote->unit_rate,$baseline_unit_rate);


                //If should include row based on plan. e.g only "steel merchant" supplier group
                $include = false;

                $getProductMatchOptions = $productService->getProductMatchOptions($business,$rawMaterialQuote);

                if($getProductMatchOptions){
                    /**
                     * 1) Non-price book (will be user custom product)
                     * UPGRADED has custom product ability
                     */
                    if($getProductMatchOptions["status"] === "CUSTOM"){
                        if($business->upgraded){
                            $include = true;

                            $requiresCustom[] = [
                                "selected" => [
                                    "product_category" => null,
                                    "material" => null,
                                    "grade" => null,
                                    "nominal_length" => null,
                                    "nominal_width" => null,
                                    "nominal_height" => null,
                                    "nesting_algo" => null,
                                    "purchasable_length_1" => null,
                                    "purchasable_length_2" => null,
                                    "purchasable_length_3" => null,
                                    "purchasable_width_1" => null,
                                    "purchasable_width_2" => null,
                                    "purchasable_width_3" => null,
                                    "suppliers" => [],
                                ],
                                "selected_other" => [
                                    "product_category" => null,
                                    "material" => null,
                                    "grade" => null,
                                    "surface" => null,
                                    "suppliers" => [],
                                ],
                                "data" => $rawMaterialQuote,
                                "nominalSizeData" => $productService->getNominalSizeData(),
                            ];
                        }
                    }

                    /**
                     * 2) Price book exact match
                     */
                    elseif($getProductMatchOptions["status"] === "EXACT"){
                        //Upgraded (shows custom options)
                        if($business->upgraded){
                            $rawMaterialQuote["product"] = $getProductMatchOptions['decodedOption'];
                            $include = true;
                        }
                        //Standard
                        else{
                            if($getProductMatchOptions["supplierGroup"] === SupplierGroupEnums::STEEL_MERCHANT->value){
                                $rawMaterialQuote["product"] = $getProductMatchOptions['decodedOption'];
                                $include = true;
                            }
                        }
                    }

                    /**
                     * 3) Price book partial match (requires confirmation)
                     */
                    elseif($getProductMatchOptions["status"] === "PARTIAL"){
                        //Upgraded (shows custom options)
                        if($business->upgraded){
                            $partialProductMatches[] = [
                                "selected" => null,
                                "data" => $rawMaterialQuote, //todo needs "nesting_algo"
                                "options" => $getProductMatchOptions['decodedOptions'],
                                "custom" => $getProductMatchOptions['custom'],
                            ];
                        }
                        //Standard
                        else{
                            if($getProductMatchOptions["supplierGroup"] === SupplierGroupEnums::STEEL_MERCHANT->value){
                                $partialProductMatches[] = [
                                    "selected" => null,
                                    "data" => $rawMaterialQuote, //todo needs "nesting_algo"
                                    "options" => $getProductMatchOptions['decodedOptions'],
                                    "custom" => $getProductMatchOptions['custom'],
                                ];
                            }
                        }
                    }
                }

                /**
                 * product categories
                 */
                $productCategories[] = $rawMaterialQuote["product_category"];

                /**
                 * Mill products //todo: get from master_materials
                 */
                foreach($allCertificateProductLabels as $mp){
                    //Could be enum or string
                    $value = gettype($mp) === "object" ? $mp->value : $mp;

                    if(strtoupper($rawMaterialQuote->product_category) == strtoupper($value)){
                        $hasCertificateProducts = true;
                    }
                }

                //Append Array
                if($include){
                    $materialListRows[] = $rawMaterialQuote;
                }
            }

            /**
             * Sense checks
             */
            $productCategories = array_filter(array_unique($productCategories));
            $senseChecks = $productService->senseChecks($materialListRows,$productCategories,$hasCertificateProducts);

            /**
             * Custom options (form select options)
             */
            $allGrades = $nestingService->allGradeLabels();
            $allMeasurements = $nestingService->allMeasurementUnitLabels();
            $formDependentData = $nestingService->buildDependencyArray2();

            /**
             * Nesting groups
             */
            $nestingGroups = $nestingService->getNestingGroups();

//            dd([
//                "thing" => 8,
//                "partialProductMatches" => $partialProductMatches,
//                "requiresCustom" => $requiresCustom,
//                "materialListRows" => $materialListRows,
//            ]);

            return response()->json([
                'downloadedBomData' => [
                    "project_id" => $project->id,
                    "data" => [
                        "project" => $project,
                        "materialListRows" => $materialListRows,
                        "senseChecks" => $senseChecks,
                        "partialProductMatches" => $partialProductMatches,
                        "requiresCustom" => $requiresCustom,
                        "allMeasurements" => $allMeasurements,
                        "formDependentData" => $formDependentData,
                        "allGrades" => $allGrades,
                        "business" => $project->user->business,
                        "nestingGroups" => $nestingGroups,
                    ],
                ],
            ]);
        })->name("download.bom");

        /**
         * @deprecated
         */
        Route::get("download-nesting/{batch_id}",function(Request $request, int $batch_id){
            /**
             * batch_id = 0 represents "ready to nest" which has no batch object created yet
             */
            $batch = $batch_id === 0 ? null : Batch::findOrFail($batch_id);

            //Services
            $nestingService = new NestingService();

            //Prerequisite variables
            $user = auth()->user();
            $business = $user->business;

            //View data
            $batchData = $batch
                //Batch nesting
                ? $nestingService->getBatchDataForView("BATCH",$business,$batch)

                //Suggested
                : $nestingService->getBatchDataForView("SUGGESTED",$business,null);

            return response()->json([
                'downloadedNestingData' => [
                    "batch_id" => $batch ? $batch->id : 0,
                    "data" => $batchData,
                ],
            ]);
        })->name("download.nesting");

        Route::get("quote-order-management/{batch}", QuoteOrderManagementController::class)->name("quote.order.management");

        /**
         * @deprecated
         */
        Route::get("download-quotes-data/{batch}",function(Request $request, Batch $batch){
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
            $totalOrdersQty = $batchService->totalOrdersQty($batch);

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

            return response()->json([
                'downloadedQuotesData' => $quotesAndOrders,
            ]);
        })->name("download.quotes.data");

//        Route::get("download-orders-data/{batch}",function(Request $request, Batch $batch){
//            //Services
//            $nestingService = new NestingService();
//
//            //Prerequisite variables
//            $user = auth()->user();
//            $business = $user->business;
//
//            /**
//             * "Add Quote Requests"
//             * 1) Assign a letter to each project. A, B, C, etc
//             * 2) Get all nested pieces
//             * 3) Group nested pieces by nesting algorithm. e.g "meterage"
//             */
//
//            //1) Assign a letter to each project. A, B, C, etc
//            $lettersProjectArray = $nestingService->getLetterProjectArray($batch->pieces);
//
//            //2) Get all nested pieces
//            $piecesNested = $nestingService->piecesNested($batch->pieces,$lettersProjectArray);
//
//            //3) Group nested pieces by nesting algorithm. e.g "meterage"
//            $batchGroups = $nestingService->batchGroups($piecesNested, $business);
//
//            /**
//             * "current quote coverage"
//             * 1) Get list of all supplier categories with contained products. e.g "steel merchant" contains "PFC, UB, etc"
//             * 2) Build an array that includes a string of included products. e.g "PFC, UB, UC..."
//             * 3) Check if the supplier category matching nested pieces
//             * 4) Build an array that contains "included products" and "qty quotes"
//             */
//            $currentQuoteCoverage = [];
//
//            //1) Get list of all supplier categories with contained products. e.g "steel merchant" contains "PFC, UB, etc"
//            $supplierCategoriesWithIncludedProducts = (new SupplierService())->supplierGroups($business);
//
//            //2) Build an array that includes a string of included products. e.g "PFC, UB, UC..."
//            $supplierCategoriesFormatted = [];
//            foreach($supplierCategoriesWithIncludedProducts as $supplierCategory => $includedProducts){
//                $supplierCategoriesFormatted[$supplierCategory] = [
//                    "includedProducts" => [
//                        "array" => $includedProducts,
//                        "string" => implode(", ",$includedProducts),
//                    ],
//                ];
//            }
//
//            //3) Check if the supplier category matching nested pieces
//            foreach($supplierCategoriesFormatted as $supplierCategory => $data){
//                //4) Build an array that contains "included products" and "qty quotes"
//                $batchGroup = $batchGroups["assigned"][$supplierCategory] ?? null;
//
//                if($batchGroup){
//                    $appended = $data;
//                    $appended["quotes"] = $batch->quotes()
//                        ->with("supplier")
//                        ->where("supplier_category",$supplierCategory)
//                        ->where("quote_sent",true)
//                        ->get();
//                    $appended["qtyQuotes"] = $batch->quotes()
//                        ->where("supplier_category",$supplierCategory)
//                        ->where("quote_sent",true)
//                        ->count();
//                    $appended["batchGroup"] = $batchGroup;
//                    $appended["orders"] = $batch->orders;
//                    $appended["supplier_category"] = $supplierCategory;
//
//                    $orderedOrder = $batch->orders()
//                        ->whereRelation("quote","quote_sent","=",true)
//                        ->where("order_sent",true)
//                        ->first();
//
//                    $appended["selectedSupplierId"] = $orderedOrder ? $orderedOrder->supplier_id : null;
//                    $appended["orderSent"] = (bool) $orderedOrder;
//
//                    $currentQuoteCoverage[$supplierCategory] = $appended;
//                }
//            }
//
//            return response()->json([
//                'downloadedOrdersData' => [
//                    "batch_id" => $batch->id,
//                    "data" => [
//                        "currentQuoteCoverage" => $currentQuoteCoverage,
//                    ],
//                ],
//            ]);
//        })->name("download.orders.data");

        Route::get("download-usage-data",function(Request $request){
            $nestingService = new NestingService();

            $user = auth()->user();
            $business = $user->business;

            $piecesReadyForBatching = $nestingService->piecesReadyForBatching($business);
            $lettersProjectArray = $nestingService->getLetterProjectArray($piecesReadyForBatching);
            $piecesNested = $nestingService->piecesNested($piecesReadyForBatching,$lettersProjectArray);

            return response()->json([
                'usageData' => $nestingService->usage($piecesNested),
            ]);
        })->name("download.usage.data");

        //Raw Material Quotes
        Route::name("raw.material.quote.")->group(function () {
            Route::post("raw-material-quote-bulk-destroy",RawMaterialListBulkDeleteController::class)->name("bulk.destroy");
            Route::post("raw-material-quote-clarifications/{business}", RawMaterialListClarificationsController::class)->name("clarifications");
            Route::post("raw-material-quote-customisations/{business}", RawMaterialListCustomisationsController::class)->name("customisations");
        });
        Route::controller(RawMaterialQuoteController::class)->group(function () {
            Route::delete('/raw-material-quote/{rawMaterialQuote}', 'destroy')->name("raw.material.quote.destroy"); //DELETE /photos/{photo}	destroy	photos.destroy
        });

        //Pricebook
        Route::get("pricebook", PricebookController::class)->name("pricebook");

        //Suppliers
        Route::controller(SupplierController::class)->group(function () {
            Route::get("/suppliers/{business}",'index')->name("suppliers.index");
            Route::post('/suppliers/{business}', 'store')->name("suppliers.store");
            Route::put("/suppliers/{supplier}","update")->name("suppliers.update");
            Route::delete("/suppliers/{supplier}","destroy")->name("suppliers.destroy");
        });

        //Quotes
        Route::resource('quotes', QuoteController::class);

        //Orders
        Route::resource('orders', OrderController::class);
        Route::post("order-sent/{batch}",function(Request $request, Batch $batch){
            /**
             * Update or create quote & order based on BATCH and SUPPLIER_CATEGORY
             * Action 1: SetOrderSentForBatchSupplierGroup
             *   Action 1A: UpdateOrderSentStatus
             * Action 2: AttachPiecesToOrder
             * Action 3: UpdateOrderApprovalsForBatch
             */
            $validated = $request->validate([
                'order_id' => ['required'],
            ]);

            $orderedOrder = Order::findOrFail($validated["order_id"]);

            //Only 1 order in the batch supplier group can be TRUE
            SetOrderSentForBatchSupplierGroup::run($orderedOrder,$batch);

            //Attach pieces to order
            AttachPiecesToOrder::run($batch,$orderedOrder);

            //All project managers approve ordering materials
            UpdateOrderApprovalStatus::run($batch,true);

            return back();
        })->name("order.sent");

        Route::post("order-undo-sent/{order}",function(Order $order){
            /**
             * Undo order sent
             */
            //todo other actions required? notifications, delivery, nesting?

            $order->order_sent = false;
            $order->save();

            //Attach pieces to order
            DetachPiecesFromOrder::run($order->batch,$order);

            return back();

        })->name("order.undo.sent");

        //Suggested Nesting
        Route::get("suggested-nesting", SuggestedNestingController::class)->name("suggested.nesting");

        //Batch Nesting
        Route::get("batch-nesting/{batch}", BatchNestingController::class)->name("batch.nesting");
    });

    //Batches
    Route::resource('batches', BatchController::class);
});
