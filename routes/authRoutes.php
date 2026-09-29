<?php

use App\Http\Controllers\BatchController;
use App\Http\Controllers\BatchNestingController;
use App\Http\Controllers\BillingCheckoutController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\BillingInvoiceController;
use App\Http\Controllers\BillingPortalController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DownloadBomController;
use App\Http\Controllers\DownloadNesting;
use App\Http\Controllers\DownloadQuotesDataController;
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
use App\Http\Controllers\RawMaterialListBulkDeleteController;
use App\Http\Controllers\RawMaterialListClarificationsController;
use App\Http\Controllers\RawMaterialListCustomisationsController;
use App\Http\Controllers\RawMaterialQuoteController;
use App\Http\Controllers\SandboxController;
use App\Http\Controllers\SuggestedNestingController;
use App\Http\Controllers\SupplierController;
use App\Http\Middleware\BillingWriteAccessMiddleware;
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

    /*
     * Billing
     *
     * Outside BusinessReadyMiddleware deliberately: a trial runs from the moment the business is
     * created, so it can expire before onboarding was ever finished, and the one page that can fix
     * that must not be behind the thing it is blocked by. Outside BillingWriteAccessMiddleware for
     * the same reason - subscribing is the one write a read-only account has to be able to make.
     */
    Route::get('billing', BillingController::class)->name('billing.index');
    Route::post('billing/checkout', BillingCheckoutController::class)->name('billing.checkout');
    Route::get('billing/manage', BillingPortalController::class)->name('billing.manage');
    Route::get('billing/invoice/{plan}', BillingInvoiceController::class)->name('billing.invoice');

    /*
     * Test mode
     *
     * Outside both gates below, deliberately. Leaving test mode has to work from wherever the user
     * is, including an account that has gone read-only or never finished onboarding - being stuck
     * looking at a sandbox with no way back to the real board would be far worse than anything
     * these gates protect. Clearing is in the same position for the same reason, and it can only
     * ever delete the caller's own test rows.
     */
    Route::post('sandbox', [SandboxController::class, 'enter'])->name('sandbox.enter');
    Route::post('sandbox/leave', [SandboxController::class, 'leave'])->name('sandbox.leave');
    Route::delete('sandbox', [SandboxController::class, 'clear'])->name('sandbox.clear');

    //Onboarding is finalised
    Route::middleware([BusinessReadyMiddleware::class, BillingWriteAccessMiddleware::class])->group(function () {
        //Current Projects
        //Create/show/edit were unimplemented stubs - the dashboard modals cover them
        Route::resource('projects', ProjectController::class)->only(['index', 'store', 'update', 'destroy']);

        //Past Projects
        Route::get("past-projects", PastProjectsController::class)->name("past.projects.index");
        Route::post("mark-as-past-project/{batch}", MarkAsPastProjectController::class)->name("mark.as.past.project");

        //Products
        //Upload only - the standalone product page was superseded by the Bill of Materials modal
        Route::resource('projects.products', ProductController::class)->only(['store']);

        Route::get('download-bom/{project}', DownloadBomController::class)->name('download.bom');

        Route::get('download-nesting/{batch_id}', DownloadNesting::class)->name('download.nesting');

        //Feeds the quotes/orders modal on the projects board, which replaced a page of its own
        Route::get('download-quotes-data/{batch}', DownloadQuotesDataController::class)->name('download.quotes.data');

        Route::get('download-usage-data', DownloadUsageController::class)->name('download.usage.data');

        //Raw Material Quotes
        Route::name('raw.material.quote.')->group(function () {
            Route::post('raw-material-quote-bulk-destroy', RawMaterialListBulkDeleteController::class)->name('bulk.destroy');
            Route::post('raw-material-quote-clarifications', RawMaterialListClarificationsController::class)->name('clarifications');
            Route::post('raw-material-quote-customisations', RawMaterialListCustomisationsController::class)->name('customisations');
        });
        Route::controller(RawMaterialQuoteController::class)->group(function () {
            Route::delete('/raw-material-quote/{rawMaterialQuote}', 'destroy')->name('raw.material.quote.destroy'); //DELETE /photos/{photo}	destroy	photos.destroy
        });

        //Pricebook
        Route::get('pricebook', PricebookController::class)->name('pricebook');

        //Suppliers
        Route::controller(SupplierController::class)->group(function () {
            Route::get('/suppliers', 'index')->name('suppliers.index');
            Route::post('/suppliers', 'store')->name('suppliers.store');
            Route::put('/suppliers/{supplier}', 'update')->name('suppliers.update');
            Route::delete('/suppliers/{supplier}', 'destroy')->name('suppliers.destroy');
        });

        //Quotes
        Route::resource('quotes', QuoteController::class);

        //Offcuts
        //Index only - the other resource verbs were unimplemented, and an implicitly bound {offcut}
        //carries no business scoping
        Route::resource('offcuts', OffcutController::class)->only(['index']);

        //Orders
        Route::resource('orders', OrderController::class);
        Route::post('order-sent/{batch}', OrderSentController::class)->name('order.sent');

        Route::post('order-undo-sent/{order}', OrderUndoSentController::class)->name('order.undo.sent');

        Route::post("order-mark-delivered/{order}", OrderMarkDeliveredController::class)->name("order.mark.delivered");

        //Suggested Nesting
        Route::get('suggested-nesting', SuggestedNestingController::class)->name('suggested.nesting');

        //Batch Nesting
        //"print" is typed int and "redirect" picks the close destination, so anything else is a 404, not a 500
        Route::get('batch-nesting/{batch}/{redirect}/{print}', BatchNestingController::class)
            ->whereIn('redirect', ['current', 'past'])
            ->whereNumber('print')
            ->name('batch.nesting');
    });

    /*
     * Batches
     *
     * Gated on its own because it sits outside the BusinessReadyMiddleware group above - nesting a
     * batch is the single most expensive write in the application, so it is the last thing that
     * should stay open on a lapsed account.
     */
    Route::resource('batches', BatchController::class)
        ->middleware([BillingWriteAccessMiddleware::class]);
});
