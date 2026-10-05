<?php

use App\Http\Controllers\BatchController;
use App\Http\Controllers\BatchCertificateController;
use App\Http\Controllers\BatchMarkCutController;
use App\Http\Controllers\BatchMarkDeliveredController;
use App\Http\Controllers\BatchMarkGroupOrderedController;
use App\Http\Controllers\BatchMarkGroupQuotedController;
use App\Http\Controllers\BatchMarkOrderedController;
use App\Http\Controllers\BatchMarkQuotedController;
use App\Http\Controllers\BatchNestingController;
use App\Http\Controllers\BatchOrderListController;
use App\Http\Controllers\BillingCheckoutController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\BillingInvoiceController;
use App\Http\Controllers\BillingPortalController;
use App\Http\Controllers\BusinessPreferencesController;
use App\Http\Controllers\DownloadBatchBomController;
use App\Http\Controllers\DownloadBomController;
use App\Http\Controllers\DownloadMaterialCertificateController;
use App\Http\Controllers\DownloadMaterialListFileController;
use App\Http\Controllers\DownloadNesting;
use App\Http\Controllers\DownloadUsageController;
use App\Http\Controllers\MarkAsPastProjectController;
use App\Http\Controllers\MarkNotificationStatusController;
use App\Http\Controllers\MaterialCertificateController;
use App\Http\Controllers\MaterialListFileController;
use App\Http\Controllers\NestingEfficiencyController;
use App\Http\Controllers\NestingIndexController;
use App\Http\Controllers\OffcutController;
use App\Http\Controllers\OffcutRemoveController;
use App\Http\Controllers\OffcutRestoreController;
use App\Http\Controllers\OffcutScrapController;
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
use App\Http\Controllers\SandboxController;
use App\Http\Controllers\SuggestedNestingController;
use App\Http\Middleware\BillingWriteAccessMiddleware;
use Illuminate\Support\Facades\Route;

/*
 * Auth & verified
 */
Route::middleware(['auth', 'verified'])->group(function () {

    //Profile
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    /*
     * Notifications. One route: every button in the bell is this, with a GREEN/YELLOW/RED status,
     * and each notification type decides what its own colours mean.
     *
     * The commented-out "mark-as-read" route that sat here is gone along with its controller and the
     * Notifications.vue that called it - a component nothing rendered, posting to a route that did
     * not exist.
     */
    Route::post('mark-notification-status', MarkNotificationStatusController::class)->name('mark.notification.status');

    /*
     * Billing
     *
     * Outside BillingWriteAccessMiddleware deliberately: subscribing is the one write a read-only
     * account has to be able to make, so the page that fixes an expired trial must not be behind the
     * thing an expired trial blocks.
     *
     * BusinessReadyMiddleware used to be the other half of that sentence. It is gone - a business can
     * import from its first upload now, so there is no longer a state to be outside of.
     */
    Route::get('billing', BillingController::class)->name('billing.index');
    Route::post('billing/checkout', BillingCheckoutController::class)->name('billing.checkout');
    Route::get('billing/manage', BillingPortalController::class)->name('billing.manage');
    Route::get('billing/invoice/{plan}', BillingInvoiceController::class)->name('billing.invoice');

    /*
     * Test mode
     *
     * Outside both gates below, deliberately. Leaving test mode has to work from wherever the user
     * is, including an account that has gone read-only - being stuck
     * looking at a sandbox with no way back to the real board would be far worse than anything
     * these gates protect. Clearing is in the same position for the same reason, and it can only
     * ever delete the caller's own test rows.
     */
    Route::post('sandbox', [SandboxController::class, 'enter'])->name('sandbox.enter');
    Route::post('sandbox/leave', [SandboxController::class, 'leave'])->name('sandbox.leave');
    Route::delete('sandbox', [SandboxController::class, 'clear'])->name('sandbox.clear');

    /*
     * The product. Everything here writes, so it is all behind the one gate that is left: whether
     * this business's account is paid up or in its trial.
     *
     * BusinessReadyMiddleware sat in front of BillingWriteAccessMiddleware here and held the lot
     * until an admin had recorded this business's import templates by hand. An upload that matches
     * nothing now writes its own template - see TemplateLearningService - so there is nothing to
     * wait for and nothing to hold.
     */
    Route::middleware([BillingWriteAccessMiddleware::class])->group(function () {
        /*
         * Business preferences - the quoting and delivery lead times, set from /profile.
         *
         * In here rather than beside the profile routes above, which are deliberately outside this
         * gate: those are the person's own name, email and password, and a lapsed account still has
         * to be able to sign in and fix its card. These two numbers are the business's configuration
         * and they move every deadline on the board, which is a write like any other.
         */
        Route::patch('business-preferences', [BusinessPreferencesController::class, 'update'])
            ->name('business.preferences.update');

        /*
         * Current Projects
         *
         * Create/show/edit were unimplemented stubs - the dashboard modals cover them - and index is
         * gone with the Kanban board it rendered. /nesting carries the workflow now, so what is left
         * here is the three writes the upload page and the Nesting page already post to.
         */
        Route::resource('projects', ProjectController::class)->only(['store', 'update', 'destroy']);

        //Past Projects
        Route::get("past-projects", PastProjectsController::class)->name("past.projects.index");
        Route::post("mark-as-past-project/{batch}", MarkAsPastProjectController::class)->name("mark.as.past.project");

        //Products
        //Upload only - the standalone product page was superseded by the Bill of Materials modal
        Route::resource('projects.products', ProductController::class)->only(['store']);

        Route::get('download-bom/{project}', DownloadBomController::class)->name('download.bom');

        /*
         * Every material row on a batch, for the read-only BOM the Nesting page's cards open. Optional
         * batch: with none, it answers for the batch that has not been created yet - everything on the
         * Nesting column - which is the one card on that page with no id to pass.
         */
        Route::get('download-batch-bom/{batch?}', DownloadBatchBomController::class)->name('download.batch.bom');

        /*
         * The spreadsheets a batch's materials were imported from, listed by the BOM above.
         *
         * The download is open to the business, like the BOM itself - "which revision is this steel
         * off" is asked by whoever is buying it. The delete is not: it takes a whole upload and every
         * material row that came out of it off the job, so it is the project's manager or whoever
         * uploaded for them, and it is refused once that steel is on a batch. See the controller.
         */
        Route::get('material-list-files/{materialListFile}/download', DownloadMaterialListFileController::class)
            ->name('material.list.file.download');
        Route::delete('material-list-files/{materialListFile}', [MaterialListFileController::class, 'destroy'])
            ->name('material.list.file.destroy');

        /*
         * What to buy for a batch, a block per supplier group - the Nesting page's "Order list" modal.
         * Optional batch for the same reason as the BOM above: with none, it answers for everything on
         * the Nesting column, which has no batch to name yet.
         */
        Route::get('batch-order-list/{batch?}', BatchOrderListController::class)->name('batch.order.list');

        Route::get('download-nesting/{batch_id}', DownloadNesting::class)->name('download.nesting');

        Route::get('download-usage-data', DownloadUsageController::class)->name('download.usage.data');

        //Raw Material Quotes
        Route::name('raw.material.quote.')->group(function () {
            Route::post('raw-material-quote-bulk-destroy', RawMaterialListBulkDeleteController::class)->name('bulk.destroy');
            Route::post('raw-material-quote-clarifications', RawMaterialListClarificationsController::class)->name('clarifications');
            Route::post('raw-material-quote-customisations', RawMaterialListCustomisationsController::class)->name('customisations');
        });
        /*
         * The single-row DELETE that sat here is gone, along with RawMaterialQuoteController and its
         * policy. Nothing called it - the BOM modal and the product index both post the bulk route
         * above - and it was a bare $rawMaterialQuote->delete(), so any row that had reached a piece
         * answered with a 500 off the restricting pieces.raw_material_quote_id. Deleting a material
         * row is not a one-liner: see the bulk controller, which refuses a row already quoted or
         * ordered, detaches the piece from its quotes, and clears up the quotes and batches that are
         * left with nothing in them.
         */

        //Pricebook
        Route::get('pricebook', PricebookController::class)->name('pricebook');

        /*
         * Quotes
         *
         * only(): index, create, show and edit had no handler at all, and destroy had nothing but a
         * Gate call - so a DELETE on a quote authorised the caller, deleted nothing, and answered
         * 200. That is worse than a 404: it reads as a success to anything that wires itself up to
         * it. Nothing in resources/js ever did, which is the only reason it never bit.
         */
        Route::resource('quotes', QuoteController::class)->only(['store', 'update']);

        //Offcuts
        //Index only - the other resource verbs were unimplemented, and an implicitly bound {offcut}
        //carries no business scoping
        Route::resource('offcuts', OffcutController::class)->only(['index']);

        /*
         * Taking a piece of steel out of inventory by hand, and putting it back.
         *
         * Not offcuts.destroy: nothing is deleted. The row carries the certificate trail of every
         * offcut cut from it and holds its mark out of circulation, so it is flagged, never removed
         * (see the 2026_09_30 migration). {offcut} is a plain integer that the controller looks up in
         * the business's own inventory - implicit binding would hand any business any offcut.
         */
        Route::post('offcuts/{offcut}/remove', OffcutRemoveController::class)
            ->whereNumber('offcut')
            ->name('offcuts.remove');

        Route::post('offcuts/{offcut}/restore', OffcutRestoreController::class)
            ->whereNumber('offcut')
            ->name('offcuts.restore');

        /*
         * Weighing in the dead stock the quarterly cleanout put up. Takes the ids in the body rather
         * than one at a time: clearing a rack is one decision about a list, and a row-at-a-time
         * endpoint would have the list shifting under each press as earlier ones left it.
         *
         * Not offcuts.remove with a reason of SCRAPPED, even though that is what it records. This one
         * will only touch steel that is on the business's own cleanout list today - see
         * OffcutScrapController.
         */
        Route::post('offcuts/scrap', OffcutScrapController::class)->name('offcuts.scrap');

        /*
         * Orders
         *
         * only(): index, create, show and edit are unimplemented, and an unimplemented GET answers
         * with a blank 200 rather than a 404 - see the quotes resource above.
         */
        Route::resource('orders', OrderController::class)->only(['store', 'update', 'destroy']);
        Route::post('order-sent/{batch}', OrderSentController::class)->name('order.sent');

        Route::post('order-undo-sent/{order}', OrderUndoSentController::class)->name('order.undo.sent');

        Route::post("order-mark-delivered/{order}", OrderMarkDeliveredController::class)->name("order.mark.delivered");

        /*
         * The same steps said of the whole batch, naming no supplier - the Nesting page's "All
         * quoted", "All ordered" and "Delivered".
         *
         * Beside the per-supplier routes above because they record the same facts, and apart from them
         * because they do none of the work: nothing is sent, no quote, order or goods receipt row is
         * touched and the batch does not change column. They are for a shop that buys off the
         * application entirely, which has no supplier rows to tick - see BatchMarkQuotedController.
         *
         * "Cut" is the odd one in the list. It records the step after delivery, which happens on every
         * job however the steel was bought, so it is the one of these offered on an order-driven batch
         * too - see BatchMarkCutController.
         */
        Route::post('batch-all-quoted/{batch}', BatchMarkQuotedController::class)->name('batch.all.quoted');
        Route::post('batch-all-ordered/{batch}', BatchMarkOrderedController::class)->name('batch.all.ordered');
        Route::post('batch-all-delivered/{batch}', BatchMarkDeliveredController::class)->name('batch.all.delivered');
        Route::post('batch-cut/{batch}', BatchMarkCutController::class)->name('batch.cut');
        //And the same two said of one merchant rather than the whole batch - the order list's own
        Route::post('batch-group-quoted/{batch}', BatchMarkGroupQuotedController::class)->name('batch.group.quoted');
        Route::post('batch-group-ordered/{batch}', BatchMarkGroupOrderedController::class)->name('batch.group.ordered');

        /*
         * Material certificates - the file half of them. The written reference is a column on the
         * order and is saved through orders.update with everything else.
         *
         * Two ways in, one table: against an order, which is where a merchant's PDF lands for a shop
         * that buys through the quotes screen, and against a batch, which is where it lands for a shop
         * that rings the merchant and has no order row at all. The two writing routes are separate
         * because the thing being authorised is different; removing and downloading are shared,
         * because by then it is one row either way.
         */
        Route::post('orders/{order}/material-certificates', [MaterialCertificateController::class, 'store'])
            ->name('material.certificates.store');
        Route::delete('material-certificates/{materialCertificate}', [MaterialCertificateController::class, 'destroy'])
            ->name('material.certificates.destroy');
        Route::get('material-certificates/{materialCertificate}/download', DownloadMaterialCertificateController::class)
            ->name('material.certificates.download');
        Route::get('batches/{batch}/material-certificates', [BatchCertificateController::class, 'index'])
            ->name('batch.certificates.index');
        Route::post('batches/{batch}/material-certificates', [BatchCertificateController::class, 'store'])
            ->name('batch.certificates.store');

        /*
         * Nesting - and the home page, which is why it is named "dashboard".
         *
         * Every live batch in one list, headed by the open batch everything waiting is nested into.
         * It took over /dashboard when the page that was there - a pipeline summary over an upload
         * form - was deleted, and it kept that route name because login, registration and all four
         * email verification controllers redirect to route('dashboard').
         *
         * In this group with the rest of nesting: the only thing the page offers is a way into
         * batch.nesting below, which is gated here. Safe as the screen login lands on even so -
         * BillingWriteAccessMiddleware holds writes, not GETs, so a lapsed account still arrives at
         * a page it can read.
         */
        Route::get('/dashboard', NestingIndexController::class)->name('dashboard');

        //How well each batch nested, fetched by that page once it has drawn - see the controller
        Route::get('nesting-efficiency', NestingEfficiencyController::class)->name('nesting.efficiency');

        /*
         * Suggested Nesting
         *
         * "print" picks the same two screens batch.nesting below picks between, and for the same
         * reason: the open batch is read on the board as a modal and on /nesting as the printable
         * sheet, so both have to be reachable. Optional and zero by default - the board asks for it
         * without a parameter, and a nest nobody can buy yet should not default to a cutting sheet.
         */
        Route::get('suggested-nesting/{print?}', SuggestedNestingController::class)
            ->whereNumber('print')
            ->name('suggested.nesting');

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
     * Gated on its own because it sits outside the group above - nesting a batch is the single most
     * expensive write in the application, so it is the last thing that should stay open on a lapsed
     * account.
     */
    /*
     * only(): destroy is the unwind; store exists to answer 404 to anything that tries to create a
     * batch directly, since batches are created by QuoteController::store. index, create, show, edit
     * and update were unimplemented - update authorised the caller and then wrote nothing at all.
     */
    Route::resource('batches', BatchController::class)
        ->only(['store', 'destroy'])
        ->middleware([BillingWriteAccessMiddleware::class]);
});
