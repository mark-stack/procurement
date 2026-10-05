<?php

use App\Http\Controllers\AdminImpersonationController;
use App\Http\Controllers\AdminMaterialDestroyController;
use App\Http\Controllers\AdminMaterialExportController;
use App\Http\Controllers\AdminMaterialImportController;
use App\Http\Controllers\AdminMaterialIndexController;
use App\Http\Controllers\AdminMaterialStoreController;
use App\Http\Controllers\AdminMaterialUpdateController;
use App\Http\Controllers\AdminNestingAlgorithmController;
use App\Http\Controllers\AdminNotificationIndexController;
use App\Http\Controllers\AdminStopImpersonationController;
use App\Http\Controllers\AdminTemplateAttemptResolveController;
use App\Http\Controllers\AdminTemplateAttemptSampleController;
use App\Http\Controllers\AdminTemplateProposalController;
use App\Http\Controllers\AdminTemplateReviewController;
use App\Http\Controllers\AdminTemplateScreenshotController;
use App\Http\Controllers\AdminTemplateTestController;
use App\Http\Controllers\AdminUserIndexController;
use App\Http\Controllers\ProofController;
use App\Http\Controllers\ResendWelcomeEmailController;
use App\Http\Controllers\TemplateController;
use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\PlatformProductMiddleware;
use Illuminate\Support\Facades\Route;

/*
 * 'verified' after AdminMiddleware, not before: AdminMiddleware is the one that knows how to answer
 * a signed-out visitor (login, with the url it stored) and a non-admin (home, with a reason), and
 * putting the verification check in front of it would answer both with the verification prompt.
 *
 * It is here at all because the admin panel is the only group in this application that was gated on
 * identity alone. Nothing in it was reachable without the is_admin column now, but an admin account
 * whose address has never been confirmed should not be editing every business's templates either.
 */
Route::prefix('admin')->name('admin.')->middleware([AdminMiddleware::class, 'verified'])->group(function () {
    //Templates
    //scoped(): {template} must belong to {business}, or a mistyped URL edits/deletes
    //another business's row while the page you are on shows nothing changed
    //only(): the screen is a single create-and-list page, so create/show/edit have no
    //handlers and would otherwise answer with a blank 200
    Route::resource('businesses.templates', TemplateController::class)
        ->scoped()
        ->only(['index', 'store', 'update', 'destroy']);

    //Reads an uploaded sample spreadsheet and fills the template form in from it. POST because it
    //takes a file and spends money at OpenAI, but it writes nothing - the form still has to be
    //submitted by hand afterwards.
    Route::post('businesses/{business}/templates/propose', AdminTemplateProposalController::class)
        ->name('businesses.templates.propose');

    //Runs the importer over an uploaded sample using the values in the form, and reports every row
    //it would extract and what would become of each. POST because it takes a file; it writes
    //nothing at all - no template, no project, no material row.
    Route::post('businesses/{business}/templates/test', AdminTemplateTestController::class)
        ->name('businesses.templates.test');

    //scopeBindings(): same reason as scoped() above - without it a mistyped business id
    //serves another business's screenshot
    Route::get('businesses/{business}/templates/{template}/screenshot', AdminTemplateScreenshotController::class)
        ->scopeBindings()
        ->name('businesses.templates.screenshot');

    /*
     * Records that a person has read a template a customer's upload wrote for itself. POST because it
     * writes, and nothing else: the template is already live, and reviewing it changes nothing about
     * what the importer reads. scopeBindings() so {template} must belong to {business}.
     */
    Route::post('businesses/{business}/templates/{template}/reviewed', AdminTemplateReviewController::class)
        ->scopeBindings()
        ->name('businesses.templates.reviewed');

    /*
     * Failed learning attempts: the uploads that matched no template and could not be read
     * automatically. The only part of setting a customer up that still needs a person.
     *
     * The sample is a customer's bill of materials on a private disk, so this route is the only way
     * to it. Both are scoped to {business} in the controller rather than by scopeBindings(), because
     * the binding is on a plain id and the relation name would have to be guessed from it.
     */
    Route::get('businesses/{business}/template-attempts/{attempt}/sample', AdminTemplateAttemptSampleController::class)
        ->whereNumber('attempt')
        ->name('businesses.template.attempts.sample');

    //POST: it writes, and it deletes the stored spreadsheet - not something a link or a prefetch does
    Route::post('businesses/{business}/template-attempts/{attempt}/resolve', AdminTemplateAttemptResolveController::class)
        ->whereNumber('attempt')
        ->name('businesses.template.attempts.resolve');

    //Master materials
    //The catalogue is edited here row by row. It used to be authored in a spreadsheet and
    //re-imported wholesale, which meant one click rewrote all 1,150 rows and any correction
    //made in the app was deprecated and duplicated by the next import.
    Route::get('materials', AdminMaterialIndexController::class)->name('materials.index');
    Route::post('materials', AdminMaterialStoreController::class)->name('materials.store');

    //Bulk changes as JSON: generated elsewhere, or exported from the other environment.
    //Always two steps - preview writes nothing, apply re-derives the plan and refuses it if the
    //catalogue moved while it was being reviewed.
    Route::post('materials/import/preview', [AdminMaterialImportController::class, 'preview'])->name('materials.import.preview');
    Route::post('materials/import/apply', [AdminMaterialImportController::class, 'apply'])->name('materials.import.apply');

    //GET: reads nothing but the catalogue and changes nothing
    Route::get('materials/export', AdminMaterialExportController::class)->name('materials.export');

    //PlatformProductMiddleware: {product} resolves by id alone, so without it a mistyped id
    //edits or deletes a business's own private product
    Route::middleware([PlatformProductMiddleware::class])->group(function () {
        Route::patch('materials/{product}', AdminMaterialUpdateController::class)->name('materials.update');
        Route::delete('materials/{product}', AdminMaterialDestroyController::class)->name('materials.destroy');
    });

    //Users
    Route::get('users', AdminUserIndexController::class)->name('users.index');

    /*
     * Notifications: every bell entry and every email this application has sent, with the channel on
     * each row. The users list links here with ?channel=mail&user={id} - "what have we emailed this
     * person" - and the page's own pills widen it back out.
     *
     * Read-only, and there is deliberately no resend here. The one thing worth sending by hand is the
     * welcome and login link, which has its own button on the users list and its own confirmation.
     */
    Route::get('notifications', AdminNotificationIndexController::class)->name('notifications.index');

    //POST: this swaps which account the session is authenticated as, so it must not be
    //reachable by a link, a prefetch or a cross-site <img> pointed at a logged-in admin
    Route::post('impersonate/{user}', AdminImpersonationController::class)->name('impersonate');

    //POST: this sends mail, so it must not be reachable by a link, a prefetch or the back
    //button. Per user rather than per business - see the controller
    Route::post('resend-welcome/{user}', ResendWelcomeEmailController::class)->name('resend.welcome');

    /*
     * Activate and deactivate stood here. Activating wrote admin_setup_complete, started the trial and
     * welcomed every user in the business by email; deactivating put them all back on onboarding.
     *
     * Both are gone with the column. A business can import from its first upload - see
     * TemplateLearningService - so there is nothing for an admin to switch on, and the trial now runs
     * from registration because that is when the product starts working. What is left of the pair is
     * resend-welcome above, which is a way to get one customer into their account.
     */

    //Nesting algorithm
    //{business?}: the cost settings are per business, so an admin can inspect any of them, but the
    //page explains the algorithm either way and defaults to the admin's own
    Route::get('nesting-algorithm/{business?}', AdminNestingAlgorithmController::class)->name('nesting.algorithm');
});

/*
 * Proof: three nesting lifecycles run end to end, offcuts-of-offcuts included.
 *
 * Admin-only and gated exactly as the group above is, but at /proof rather than /admin/proof - it is the
 * page you open in front of somebody to answer "does the nesting actually work", and a url you can say out
 * loud is worth more here than tidiness in the routes file.
 *
 * It writes nothing and reads nothing: every scenario is fixed and nested in memory, so this is a GET with
 * no model binding and no business to scope to. See Services\NestingProof.
 */
Route::get('proof', ProofController::class)
    ->middleware([AdminMiddleware::class, 'verified'])
    ->name('proof');

//Outside the group on purpose: while impersonating, isAdmin() reads the impersonated
//user's email, so AdminMiddleware would block the only route back out
Route::post('admin/stop-impersonating', AdminStopImpersonationController::class)
    ->middleware('auth')
    ->name('admin.stop.impersonating');
