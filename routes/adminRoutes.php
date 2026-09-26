<?php

use App\Http\Controllers\ActivateBusinessController;
use App\Http\Controllers\AdminImpersonationController;
use App\Http\Controllers\AdminStopImpersonationController;
use App\Http\Controllers\AdminSupplierIndexController;
use App\Http\Controllers\AdminSupplierStoreController;
use App\Http\Controllers\AdminUpdateMasterMaterialsSpreadsheetController;
use App\Http\Controllers\AdminUserIndexController;
use App\Http\Controllers\TemplateController;
use App\Http\Middleware\AdminMiddleware;
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

    //Update master materials spreadsheet
    //POST: this rewrites the whole platform catalogue, so it must not be reachable by a link,
    //a prefetch or a crawler
    Route::post('update-master-materials-spreadsheet', AdminUpdateMasterMaterialsSpreadsheetController::class)->name('update.master.materials.spreadsheet');

    //Users
    Route::get('users', AdminUserIndexController::class)->name('users.index');

    //POST: this swaps which account the session is authenticated as, so it must not be
    //reachable by a link, a prefetch or a cross-site <img> pointed at a logged-in admin
    Route::post('impersonate/{user}', AdminImpersonationController::class)->name('impersonate');

    //Business
    //POST: activating emails every user in the business, so it must not be reachable by a
    //link, a prefetch, a crawler or the back button
    Route::post('activate-business/{business}', ActivateBusinessController::class)->name('activate.business');

    //Suppliers
    Route::get('/suppliers/{business}', AdminSupplierIndexController::class)->name('suppliers.index');
    Route::post('/suppliers/{business}', AdminSupplierStoreController::class)->name('suppliers.store');
});

//Outside the group on purpose: while impersonating, isAdmin() reads the impersonated
//user's email, so AdminMiddleware would block the only route back out
Route::post('admin/stop-impersonating', AdminStopImpersonationController::class)
    ->middleware('auth')
    ->name('admin.stop.impersonating');
