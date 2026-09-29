<?php

use App\Http\Controllers\ActivateBusinessController;
use App\Http\Controllers\AdminImpersonationController;
use App\Http\Controllers\AdminMaterialDestroyController;
use App\Http\Controllers\AdminMaterialExportController;
use App\Http\Controllers\AdminMaterialImportController;
use App\Http\Controllers\AdminMaterialIndexController;
use App\Http\Controllers\AdminMaterialStoreController;
use App\Http\Controllers\AdminMaterialUpdateController;
use App\Http\Controllers\AdminNestingAlgorithmController;
use App\Http\Controllers\AdminStopImpersonationController;
use App\Http\Controllers\AdminSupplierIndexController;
use App\Http\Controllers\AdminSupplierStoreController;
use App\Http\Controllers\AdminTemplateProposalController;
use App\Http\Controllers\AdminTemplateScreenshotController;
use App\Http\Controllers\AdminTemplateTestController;
use App\Http\Controllers\AdminUserIndexController;
use App\Http\Controllers\DeactivateBusinessController;
use App\Http\Controllers\ResendWelcomeEmailController;
use App\Http\Controllers\TemplateController;
use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\PlatformProductMiddleware;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')->name('admin.')->middleware([AdminMiddleware::class])->group(function () {
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

    //POST: this swaps which account the session is authenticated as, so it must not be
    //reachable by a link, a prefetch or a cross-site <img> pointed at a logged-in admin
    Route::post('impersonate/{user}', AdminImpersonationController::class)->name('impersonate');

    //POST: this sends mail, so it must not be reachable by a link, a prefetch or the back
    //button. Per user rather than per business - see the controller
    Route::post('resend-welcome/{user}', ResendWelcomeEmailController::class)->name('resend.welcome');

    //Business
    //POST: activating emails every user in the business, so it must not be reachable by a
    //link, a prefetch, a crawler or the back button
    Route::post('activate-business/{business}', ActivateBusinessController::class)->name('activate.business');

    //POST: it writes, and it puts every user in the business back on onboarding. It sends no
    //mail, which is the one way it is not activation's mirror
    Route::post('deactivate-business/{business}', DeactivateBusinessController::class)->name('deactivate.business');

    //Nesting algorithm
    //{business?}: the cost settings are per business, so an admin can inspect any of them, but the
    //page explains the algorithm either way and defaults to the admin's own
    Route::get('nesting-algorithm/{business?}', AdminNestingAlgorithmController::class)->name('nesting.algorithm');

    //Suppliers
    Route::get('/suppliers/{business}', AdminSupplierIndexController::class)->name('suppliers.index');
    Route::post('/suppliers/{business}', AdminSupplierStoreController::class)->name('suppliers.store');
});

//Outside the group on purpose: while impersonating, isAdmin() reads the impersonated
//user's email, so AdminMiddleware would block the only route back out
Route::post('admin/stop-impersonating', AdminStopImpersonationController::class)
    ->middleware('auth')
    ->name('admin.stop.impersonating');
