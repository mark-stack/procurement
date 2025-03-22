<?php

use App\Actions\Offcut\GenerateOffcuts;
use App\Actions\Order\SetOrderSentForBatchSupplierGroup;
use App\Actions\OrderApproval\UpdateOrderApprovalStatus;
use App\Actions\Piece\AttachPiecesToOrder;
use App\Actions\Piece\DetachPiecesFromOrder;
use App\Formatters\NestingFormatter;
use App\Formatters\ProductFormatter;
use App\Formatters\QuoteFormatter;
use App\Formatters\SupplierFormatter;
use App\Http\Controllers\BatchController;
use App\Http\Controllers\BatchNestingController;
use App\Http\Controllers\MarkAsPastProjectController;
use App\Http\Controllers\MarkNotificationStatusController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PastProjectsController;
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
use App\Http\Resources\ProjectResource;
use App\Models\Batch;
use App\Models\Offcut;
use App\Models\Order;
use App\Models\Project;
use App\Models\Quote;
use App\PrerequisiteConditions\PrerequisiteConditions;
use App\Services\BatchService;
use App\Services\OrderService;
use App\Services\ProductService;
use App\Services\QuoteService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware(['auth', 'verified'])->group(function () {

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
        if ($business->admin_setup_complete) {
            return redirect()->route('projects.index');
        }
        //Not onboarded yet
        else {
            return redirect()->route('onboarding');
        }
    })->name('dashboard');

    //Notifications
    //Route::post("mark-as-read", NotificationMarkAsReadController::class)->name("notification.mark.as.read");
    Route::post('mark-notification-status', MarkNotificationStatusController::class)->name('mark.notification.status');

    //Onboarding is finalised
    Route::middleware([BusinessReadyMiddleware::class])->group(function () {
        //Current Projects
        Route::resource('projects', ProjectController::class);

        //Past Projects
        Route::get("past-projects", PastProjectsController::class)->name("past.projects.index");
        Route::post("mark-as-past-project/{batch}", MarkAsPastProjectController::class)->name("mark.as.past.project");

        //Products
        Route::controller(ProductController::class)->group(function () {
            Route::get('/{project}/products', 'index')->name('products.index'); //GET	/photos	index	photos.index
            //Route::get('/products/{id}', 'products.show')->name("products.show"); //GET	/photos/{photo}	show	photos.show
            Route::post('/{project}/products', 'store')->name('products.store'); //POST	/photos	store	photos.store
        });
        //GET	/photos/create	create	photos.create
        //GET	/photos/{photo}/edit	edit	photos.edit
        //PUT/PATCH	/photos/{photo}	update	photos.update
        //DELETE	/photos/{photo}	destroy	photos.destroy

        Route::get('download-bom/{project}', function (Request $request, Project $project) {

            /**
             * Single purpose: upload, clarify, and display consolidated BOM for a project
             */
            Gate::authorize('owned', $project);

            //Formatter
            $nestingFormatter = new NestingFormatter();
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
            $allCertificateProductLabels = $nestingFormatter->getCertificateProductLabels();
            $hasCertificateProducts = []; //todo: get from master_materials
            $requiresCustom = [];

            //Loop user's material rows
            foreach ($project->rawMaterialQuotes as $rawMaterialQuote) {
                //Append Array
                $nesting_algo = ($rawMaterialQuote->product_category && $nestingFormatter->getNestingLabelsFromProductCategory($rawMaterialQuote->product_category))
                    ? $nestingFormatter->getNestingLabelsFromProductCategory($rawMaterialQuote->product_category)[0]
                    : null;
                $rawMaterialQuote->nesting_algo = $nesting_algo;
                $rawMaterialQuote->status = $rawMaterialQuote->status();

                //If should include row based on plan. e.g only "steel merchant" supplier group
                $getProductMatchOptions = $productService->getProductMatchOptions($business, $rawMaterialQuote);

                if ($getProductMatchOptions) {
                    /**
                     * 1) Non-price book (will be user custom product)
                     * if business "allow_custom_products"
                     */
                    if ($getProductMatchOptions['status'] === 'CUSTOM') {
                        if ($business->allow_custom_products) {
                            $requiresCustom[] = (new ProductFormatter())->requiresCustomForm($rawMaterialQuote);
                        }
                    }

                    /**
                     * 2) Price book exact match
                     */
                    elseif ($getProductMatchOptions['status'] === 'EXACT') {
                        //Supplier group belongs to current plan
                        $supplierGroup = $getProductMatchOptions['supplierGroup'];
                        if ($business->supplierGroupIsCurrentPlan($supplierGroup)) {
                            $rawMaterialQuote['product'] = $getProductMatchOptions['decodedOption'];
                        }
                    }

                    /**
                     * 3) Price book partial match (requires confirmation)
                     */
                    elseif ($getProductMatchOptions['status'] === 'PARTIAL') {
                        //Supplier group belongs to current plan
                        $supplierGroup = $getProductMatchOptions['supplierGroup'];
                        if ($business->supplierGroupIsCurrentPlan($supplierGroup)) {
                            $partialProductMatches[] = [
                                'selected' => null,
                                'data' => $rawMaterialQuote, //todo needs "nesting_algo"
                                'options' => $getProductMatchOptions['decodedOptions'],
                                'custom' => $getProductMatchOptions['custom'],
                            ];
                        }
                    }
                }

                /**
                 * product categories
                 */
                $productCategories[] = $rawMaterialQuote['product_category'];

                /**
                 * Mill products //todo: get from master_materials
                 */
                foreach ($allCertificateProductLabels as $mp) {
                    //Could be enum or string
                    $value = gettype($mp) === 'object' ? $mp->value : $mp;

                    if (strtoupper($rawMaterialQuote->product_category) == strtoupper($value)) {
                        $hasCertificateProducts = true;
                    }
                }

                //Append Array
                $materialListRows[] = $rawMaterialQuote;
            }

            /**
             * Sense checks
             */
            $productCategories = array_filter(array_unique($productCategories));
            $senseChecks = $productService->senseChecks($materialListRows, $productCategories, $hasCertificateProducts);

            /**
             * Custom options (form select options)
             */
            $allGrades = (new nestingFormatter())->allGradeLabels();
            $allMeasurements = $nestingFormatter->allMeasurementUnitLabels();
            $formDependentData = $nestingFormatter->buildDependencyArray2();

            /**
             * Nesting groups
             */
            $nestingGroups = $nestingFormatter->getNestingGroups();

            return response()->json([
                'downloadedBomData' => [
                    'project_id' => $project->id,
                    'data' => [
                        "itemsNotFound" => $project->items_not_found
                            ? implode(", ",unserialize($project->items_not_found))
                            : null,
                        'percentageOfMaterialsQuoted' => $project->percentageOfMaterialsQuoted(),
                        'percentageOfMaterialsOrdered' => $project->percentageOfMaterialsOrdered(),
                        'project' => $project,
                        'materialListRows' => $materialListRows,
                        'senseChecks' => $senseChecks,
                        'partialProductMatches' => $partialProductMatches,
                        'requiresCustom' => $requiresCustom,
                        'allMeasurements' => $allMeasurements,
                        'formDependentData' => $formDependentData,
                        'allGrades' => $allGrades,
                        'business' => $project->user->business,
                        'nestingGroups' => $nestingGroups,
                    ],
                ],
            ]);
        })->name('download.bom');

        /**
         * @deprecated
         */
        Route::get('download-nesting/{batch_id}', function (Request $request, int $batch_id) {
            /**
             * batch_id = 0 represents "ready to nest" which has no batch object created yet
             */
            $batch = $batch_id === 0 ? null : Batch::findOrFail($batch_id);

            //Formatter
            $nestingFormatter = new NestingFormatter;

            //Prerequisite variables
            $user = auth()->user();
            $business = $user->business;

            //View data
            $batchData = $batch
                //Batch nesting
                ? $nestingFormatter->nestingViewData('BATCH', $business, $batch)

                //Suggested
                : $nestingFormatter->nestingViewData('SUGGESTED', $business, null);

            return response()->json([
                'downloadedNestingData' => [
                    'batch_id' => $batch ? $batch->id : 0,
                    'data' => $batchData,
                ],
            ]);
        })->name('download.nesting');

        Route::get('quote-order-management/{batch}', QuoteOrderManagementController::class)->name('quote.order.management');

        /**
         * @deprecated
         */
        Route::get('download-quotes-data/{batch}', function (Request $request, Batch $batch) {

            Gate::authorize('owned', $batch);

            //Formatter
            $batchService = new BatchService;
            $nestingFormatter = new NestingFormatter;
            $supplierService = new SupplierFormatter;

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
            $lettersProjectArray = $nestingFormatter->getLetterProjectArray($batch->pieces);

            //2) Get all nested pieces
            $piecesNested = $nestingFormatter->piecesNested($batch->pieces, $lettersProjectArray, $business);

            //3) Group nested pieces by nesting algorithm. e.g "meterage"
            $piecesGroupedBySupplierGroup = $nestingFormatter->piecesGroupedBySupplierGroup($piecesNested, $business);

            //4) get list of supplier categories available to the business
            $supplierGroupsAvailableToBusiness = $supplierService->supplierGroupsAvailableToBusiness($business);

            //5) filter out categories not features in the nesting list
            foreach ($supplierGroupsAvailableToBusiness as $supplierGroup => $includedProducts) {

                //has pieces for this supplier group
                $batchGroup = $piecesGroupedBySupplierGroup['assigned'][$supplierGroup] ?? null;

                if ($batchGroup) {

                    $rows = [];
                    $suppliers = $supplierService->suppliersForSupplierGroup($supplierGroup, $business);

                    $orderedOrder = $batch->orders()
                        ->where('order_sent', true)
                        ->first();

                    foreach ($suppliers as $supplier) {
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
                            ],
                            'formQuoteUpdate' => [
                                'batch_id' => $batch->id,
                                'quote_id' => $quote->id,
                                'quote_sent' => $quote->quote_sent,
                                'supplier_quote_reference' => $quote->supplier_quote_reference,
                                'quoted_price' => $quote->quoted_price,
                                'quoted_lead_time' => $quote->quoted_lead_time,
                            ],
                            'formOrderUpdate' => [
                                'batch_id' => $batch->id,
                                'order_id' => $order->id,
                                'supplier_group' => $supplierGroup,
                                'ordered_quote_id' => $orderedOrder ? $orderedOrder->quote->id : null,
                                'purchase_order_number' => $orderedOrder ? $orderedOrder->purchase_order_number : null,
                            ],
                            'formUndoOrderSent' => [
                                'order_id' => $order->id,
                            ],
                        ];
                    }

                    $supplierGroupCards[$supplierGroup] = [
                        'info' => [
                            'supplierGroup' => $supplierGroup,
                            'batchGroup' => $batchGroup,
                            'includedProducts' => $includedProducts,
                            'qtyQuotes' => $batch->quotes()
                                ->where('supplier_category', $supplierGroup)
                                ->where('quote_sent', true)
                                ->count(),
                            'purchaseOrderNumber' => '123-TEST', //todo
                            'delivered' => true, //todo placeholder
                        ],
                        'rows' => $rows,
                    ];
                }
            }

            //Total orders qty
            $totalOrdersQty = $batchService->totalOrdersQty($batch);

            $quotesAndOrders = [
                'info' => [
                    'totalQuotesQty' => $batch->quotes()->count(),
                    'sentQuotesQty' => $batch->quotes()->where('quote_sent', true)->count(),
                    'totalOrdersQty' => $totalOrdersQty,
                    'sentOrdersQty' => $batch->orders()->where('order_sent', true)->count(),
                    'projectManagerApprovalMessage' => $batchService->projectManagerApprovalMessage($batch),
                ],
                'supplierGroupCards' => $supplierGroupCards,
            ];

            return response()->json([
                'downloadedQuotesData' => $quotesAndOrders,
            ]);
        })->name('download.quotes.data');

        Route::get("kanban-simple",function(){
            //Services
            $quoteService = new QuoteService;
            $batchService = new BatchService;
            $supplierService = new SupplierFormatter;
            $quoteFormatter = new QuoteFormatter();

            //Prerequisite variables
            $user = auth()->user();
            $business = $user->business;
            $piecesReadyForBatching = (new NestingFormatter)->piecesReadyForBatching($business);

            /*
             * Archived projects
             */
            $archivedProjects = ProjectResource::collection(Project::query()
                ->thisBusiness($business)
                ->where("user_id",$user->id)
                ->where('archive', true)
                ->latest()
                ->get());

            $projects = [
                //Kanban column 1
                'NEW_PROJECTS' => ProjectResource::collection(Project::query()
                    ->thisBusiness($business)
                    ->where("archive",false)
                    ->doesntHave('rawMaterialQuotes')
                    ->sortByUserAndLatest()
                    ->get()),
                //Kanban column 2
                'READY_FOR_NESTING' => [
                    'projects' => ProjectResource::collection($business
                        ->projectsReadyForBatching($piecesReadyForBatching)
                        ->sortBy('created_at')),
                ],
            ];

            /**
             * Batches for quoting (Kanban column 3)
             */
            $quoted = [];
            $batchesForQuoting = $business->batches()
                ->hasNoSentOrder()
                ->active()
                ->get();

            //Sort
            $batchesForQuoting = $batchService->sortByUserAndLatest($batchesForQuoting, $business);

            foreach ($batchesForQuoting as $batch) {
                /**
                 * Modal: "add quote requests"
                 * table rows of each unique supplier-product_category.
                 * e.g "ABC Steel" who does 'fasteners' and 'steel merchant' is 2 rows
                 */

                /*
                 * Prerequisite Gate
                 */
                $offcutsAssignedToThisBatch = Offcut::query()
                    ->where("batch_to_id",$batch->id)
                    ->get();
                $prerequisiteUndoStartQuoting = (new PrerequisiteConditions())->undoStartQuoting(
                    $batch,
                    $user,
                    $offcutsAssignedToThisBatch,
                );

                $quoted[$batch->id] = [
                    'info' => [
                        'batch' => [
                            'id' => $batch->id,
                            'totalPurchasedMaterial' => 999, //todo
                            'totalUsage' => 999, //todo
                            'totalWaste' => 999, //todo
                        ],
                        'projects' => ProjectResource::collection($batch->projects()),
                        'quotes' => $batch->quotes,
                        'totalQuotesQty' => $batch->quotes()->count(),
                        'sentQuotesQty' => $batch->quotes()->where('quote_sent', true)->count(),
                        'batchQuotingDeadline' => $quoteService->batchQuotingDeadline($batch),
                        "prerequisiteUndoStartQuoting" => $prerequisiteUndoStartQuoting,
                    ],
                ];
            }
            $quoted = array_values($quoted);

            /**
             * Batches for Ordering (Kanban column 4)
             */
            $ordered = [];
            $batchesForOrdering = [];
            foreach($business->batches()->active()->get() as $batch){
                //Less than 100% order coverage
                $all100Percent = true;
                foreach($batch->projects() as $project){
                    if($project->percentageOfMaterialsOrdered() !== 100){
                        $all100Percent = false;
                    }
                }

                $sentOrdersQty = $batch->orders()->where('order_sent', true)->count();
                if(($sentOrdersQty > 0) && !$all100Percent){
                    $batchesForOrdering[] = $batch;
                }
            }
            $batchesForOrdering = collect($batchesForOrdering);

            //Sort
            $batchesForOrdering = $batchService->sortByUserAndLatest($batchesForOrdering, $business);

            foreach ($batchesForOrdering as $batch) {
                //Total orders qty
                $orders = $batch->orders;
                $totalOrdersQty = $batchService->totalOrdersQty($batch);

                $ordered[$batch->id] = [
                    'info' => [
                        'batch' => [
                            'id' => $batch->id,
                            'totalPurchasedMaterial' => 999, //todo
                            'totalUsage' => 999, //todo
                            'totalWaste' => 999, //todo
                        ],
                        'projects' => ProjectResource::collection($batch->projects()),
                        'orders' => $orders,
                        'approxDueDate' => null, //todo actual - derived from earliest project
                        'totalOrdersQty' => $totalOrdersQty,
                        'sentOrdersQty' => $batch->orders()->where('order_sent', true)->count(),
                        'all_project_manager_approvals' => (new OrderService)->allProjectManagersApproved($batch),
                    ],
                ];
            }
            $ordered = array_values($ordered);

            /**
             * Batches for Delivering (Kanban column 5)
             */
            $delivered = [];
            $batchesForDelivering = [];
            foreach($business->batches()->active()->get() as $batch){
                //100% order coverage
                $all100Percent = true;
                foreach($batch->projects() as $project){
                    if($project->percentageOfMaterialsOrdered() !== 100){
                        $all100Percent = false;
                    }
                }

                if($all100Percent){
                    $batchesForDelivering[] = $batch;
                }
            }
            $batchesForDelivering = collect($batchesForDelivering);


            //Sort
            $batchesForDelivering = $batchService->sortByUserAndLatest($batchesForDelivering, $business);

            foreach ($batchesForDelivering as $batch) {
                //Total orders qty
                $orders = $batch->orders;
                $totalOrdersQty = $batchService->totalOrdersQty($batch);
                $totalDeliveredQty = $batch->orders()->where('is_delivered', true)->count();

                $delivered[$batch->id] = [
                    'info' => [
                        'batch' => [
                            'id' => $batch->id,
                            'totalPurchasedMaterial' => 999, //todo
                            'totalUsage' => 999, //todo
                            'totalWaste' => 999, //todo
                        ],
                        'projects' => ProjectResource::collection($batch->projects()),
                        'orders' => $orders,
                        'approxDueDate' => null, //todo actual - derived from earliest project
                        'totalOrdersQty' => $totalOrdersQty,
                        'sentOrdersQty' => $batch->orders()->where('order_sent', true)->count(),
                        "totalDeliveredQty" => $totalDeliveredQty,
                        "allDelivered" => $totalDeliveredQty === $totalOrdersQty,
                        'all_project_manager_approvals' => (new OrderService)->allProjectManagersApproved($batch),
                    ],
                ];
            }
            $delivered = array_values($delivered);

            $batches = [
                'QUOTED' => $quoted,
                'ORDERED' => $ordered,
                'DELIVERED' => $delivered,
            ];

            /*
             * Prerequisite Gates
             */
            $piecesReadyForBatching = (new NestingFormatter)->piecesReadyForBatching($business);
            $projectsReadyForBatching = $business->projectsReadyForBatching($piecesReadyForBatching); //Note get this before updating pieces because it gets modified
            $prerequisiteStartQuoting = (new PrerequisiteConditions())->startQuoting(
                $user,
                $projectsReadyForBatching,
                $piecesReadyForBatching,
            );

            return Inertia::render('KanbanSimple', [
                'projects' => $projects,
                'batches' => $batches,
                'archivedProjects' => $archivedProjects,
                "prerequisiteStartQuoting" => $prerequisiteStartQuoting,
            ]);
        })->name("kanban.simple");

        Route::get('download-usage-data', function (Request $request) {
            //Formatter
            $nestingFormatter = new NestingFormatter;

            $user = auth()->user();
            $business = $user->business;

            $piecesReadyForBatching = $nestingFormatter->piecesReadyForBatching($business);
            $lettersProjectArray = $nestingFormatter->getLetterProjectArray($piecesReadyForBatching);
            $piecesNested = $nestingFormatter->piecesNested($piecesReadyForBatching, $lettersProjectArray, $business);

            return response()->json([
                'usageData' => $nestingFormatter->usageStats($piecesNested),
            ]);
        })->name('download.usage.data');

        //Raw Material Quotes
        Route::name('raw.material.quote.')->group(function () {
            Route::post('raw-material-quote-bulk-destroy', RawMaterialListBulkDeleteController::class)->name('bulk.destroy');
            Route::post('raw-material-quote-clarifications/{business}', RawMaterialListClarificationsController::class)->name('clarifications');
            Route::post('raw-material-quote-customisations/{business}', RawMaterialListCustomisationsController::class)->name('customisations');
        });
        Route::controller(RawMaterialQuoteController::class)->group(function () {
            Route::delete('/raw-material-quote/{rawMaterialQuote}', 'destroy')->name('raw.material.quote.destroy'); //DELETE /photos/{photo}	destroy	photos.destroy
        });

        //Pricebook
        Route::get('pricebook', PricebookController::class)->name('pricebook');

        //Suppliers
        Route::controller(SupplierController::class)->group(function () {
            Route::get('/suppliers/{business}', 'index')->name('suppliers.index');
            Route::post('/suppliers/{business}', 'store')->name('suppliers.store');
            Route::put('/suppliers/{supplier}', 'update')->name('suppliers.update');
            Route::delete('/suppliers/{supplier}', 'destroy')->name('suppliers.destroy');
        });

        //Quotes
        Route::resource('quotes', QuoteController::class);

        //Orders
        Route::resource('orders', OrderController::class);
        Route::post('order-sent/{batch}', function (Request $request, Batch $batch) {
            /**
             * Update or create quote & order based on BATCH and SUPPLIER_CATEGORY
             * Action 1: SetOrderSentForBatchSupplierGroup
             *   Action 1A: UpdateOrderSentStatus
             * Action 2: AttachPiecesToOrder
             * Action 3: UpdateOrderApprovalsForBatch
             */

            Gate::authorize('owned', $batch);

            $validated = $request->validate([
                'order_id' => ['required'],
            ]);

            $orderedOrder = Order::findOrFail($validated['order_id']);

            //Only 1 order in the batch supplier group can be TRUE
            SetOrderSentForBatchSupplierGroup::run($orderedOrder, $batch);

            //Attach pieces to order
            AttachPiecesToOrder::run($batch, $orderedOrder);

            //All project managers approve ordering materials
            UpdateOrderApprovalStatus::run($batch, true);

            return back();
        })->name('order.sent');

        Route::post('order-undo-sent/{order}', function (Request $request, Order $order) {
            /**
             * Undo order sent
             */
            Gate::authorize('owned', $order);

            $order->order_sent = false;
            $order->is_delivered = false;
            $order->save();

            //Detach pieces to order
            DetachPiecesFromOrder::run($order->batch, $order);

            return back();

        })->name('order.undo.sent');

        Route::post("order-mark-delivered/{order}",function(Request $request, Order $order){
            Gate::authorize('owned', $order);

            //Mark delivered
            $order->is_delivered = !$order->is_delivered;
            $order->save();

            //Generate offcuts
            //GenerateOffcuts::run($order->batch);

            return back();
        })->name("order.mark.delivered");

        //Suggested Nesting
        Route::get('suggested-nesting', SuggestedNestingController::class)->name('suggested.nesting');

        //Batch Nesting
        Route::get('batch-nesting/{batch}/{redirect}', BatchNestingController::class)->name('batch.nesting');
    });

    //Batches
    Route::resource('batches', BatchController::class);
});
