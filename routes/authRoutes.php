<?php

use App\Http\Controllers\ApproveAllProjectManagersController;
use App\Http\Controllers\BatchController;
use App\Http\Controllers\BatchNestingController;
use App\Http\Controllers\CancelBatchOrdersController;
use App\Http\Controllers\MarkAsOrderedController;
use App\Http\Controllers\MarkNotificationStatusController;
use App\Http\Controllers\MarkOrderConfirmationReceivedController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PricebookController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\QuoteController;
use App\Http\Controllers\RawMaterialListBulkDeleteController;
use App\Http\Controllers\RawMaterialListClarificationsController;
use App\Http\Controllers\RawMaterialListCustomisationsController;
use App\Http\Controllers\RawMaterialQuoteController;
use App\Http\Controllers\SuggestedNestingController;
use App\Http\Controllers\SupplierController;
use App\Http\Middleware\BusinessReadyMiddleware;
use App\Http\Resources\ProjectResource;
use App\Models\Batch;
use App\Models\Project;
use App\Services\NestingService;
use App\Services\ProductService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use Inertia\Inertia;

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
                $getProductMatchOptions = $productService->getProductMatchOptions($business,$rawMaterialQuote);

                if($getProductMatchOptions){
                    /**
                     * 1) Non-price book (will be user custom product)
                     */
                    if($getProductMatchOptions["status"] === "CUSTOM"){
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

                    /**
                     * 2) Price book exact match
                     */
                    elseif($getProductMatchOptions["status"] === "EXACT"){
                        $rawMaterialQuote["product"] = $getProductMatchOptions['decodedOption'];
                    }

                    /**
                     * 3) Price book partial match (requires confirmation)
                     */
                    elseif($getProductMatchOptions["status"] === "PARTIAL"){
                        $partialProductMatches[] = [
                            "selected" => null,
                            "data" => $rawMaterialQuote,
                            "options" => $getProductMatchOptions['decodedOptions'],
                            "custom" => $getProductMatchOptions['custom'],
                        ];
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
                $nesting_algo = ($rawMaterialQuote->product_category && $nestingService->getNestingLabelsFromProductCategory($rawMaterialQuote->product_category))
                    ? $nestingService->getNestingLabelsFromProductCategory($rawMaterialQuote->product_category)[0]
                    : null;
                $rawMaterialQuote->nesting_algo = $nesting_algo;
                $baseline_unit_rate = $productService->getBaseLineUnitRateFromGeneral($getProductMatchOptions["decodedOption"] ?? null);
                $rawMaterialQuote->baseline_unit_rate = $baseline_unit_rate;
                $rawMaterialQuote->baseline_unit_rate_comparison = $productService->getBaselineUnitRateHighLowComparison($rawMaterialQuote->unit_rate,$baseline_unit_rate);
                $materialListRows[] = $rawMaterialQuote;
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
        Route::post("approve-all-project-managers/{batch}", ApproveAllProjectManagersController::class)->name("approve.all.project.managers");
        Route::post("mark-as-ordered/{order}", MarkAsOrderedController::class)->name("mark.as.ordered");
        Route::post("mark-order-confirmation-received/{order}", MarkOrderConfirmationReceivedController::class)->name("mark.order.confirmation.received");
        Route::post("cancel-batch-orders/{batch}", CancelBatchOrdersController::class)->name("cancel.batch.orders");

        //Suggested Nesting
        Route::get("suggested-nesting", SuggestedNestingController::class)->name("suggested.nesting");

        //Batch Nesting
        Route::get("batch-nesting/{batch}", BatchNestingController::class)->name("batch.nesting");
    });

    //Batches
    Route::resource('batches', BatchController::class);
});
