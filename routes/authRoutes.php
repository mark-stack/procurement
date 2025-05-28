<?php

use App\Http\Controllers\BatchController;
use App\Http\Controllers\BatchNestingController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DownloadBomController;
use App\Http\Controllers\DownloadNesting;
use App\Http\Controllers\DownloadUsageController;
use App\Http\Controllers\MarkAsPastProjectController;
use App\Http\Controllers\MarkNotificationStatusController;
use App\Http\Controllers\OffcutController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\OrderMarkDeliveredController;
use App\Http\Controllers\OrderSentController;
use App\Http\Controllers\OrderUndoSentController;
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
use Illuminate\Support\Facades\Route;

/*
 * Auth & verified
 */
Route::middleware(['auth', 'verified'])->group(function () {

    //Onboarding
    Route::get('/onboarding', OnboardingController::class)->name('onboarding');

    //Profile
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    //Dashboard
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

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
        Route::resource('projects.products', ProductController::class);

        Route::get('download-bom/{project}', DownloadBomController::class)->name('download.bom');

        Route::get('download-nesting/{batch_id}', DownloadNesting::class)->name('download.nesting');

        Route::get('quote-order-management/{batch}', QuoteOrderManagementController::class)->name('quote.order.management');

        Route::get('download-usage-data', DownloadUsageController::class)->name('download.usage.data');

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

        //Offcuts
        Route::resource('offcuts', OffcutController::class);

        //Orders
        Route::resource('orders', OrderController::class);
        Route::post('order-sent/{batch}', OrderSentController::class)->name('order.sent');

        Route::post('order-undo-sent/{order}', OrderUndoSentController::class)->name('order.undo.sent');

        Route::post("order-mark-delivered/{order}", OrderMarkDeliveredController::class)->name("order.mark.delivered");

        //Suggested Nesting
        Route::get('suggested-nesting', SuggestedNestingController::class)->name('suggested.nesting');

        //Batch Nesting
        Route::get('batch-nesting/{batch}/{redirect}/{print}', BatchNestingController::class)->name('batch.nesting');
    });

    //Batches
    Route::resource('batches', BatchController::class);
});
