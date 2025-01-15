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
use Illuminate\Support\Facades\Route;

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
