<?php

use App\Http\Controllers\ActivateBusinessController;
use App\Http\Controllers\AdminImpersonationController;
use App\Http\Controllers\AdminSupplierIndexController;
use App\Http\Controllers\AdminSupplierStoreController;
use App\Http\Controllers\AdminUpdateMasterMaterialsSpreadsheetController;
use App\Http\Controllers\AdminUserIndexController;
use App\Http\Controllers\TemplateController;
use App\Http\Middleware\AdminMiddleware;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')->name('admin.')->middleware([AdminMiddleware::class])->group(function () {
    //Templates
    Route::resource('businesses.templates', TemplateController::class);

    //Update master materials spreadsheet
    //POST: this rewrites the whole platform catalogue, so it must not be reachable by a link,
    //a prefetch or a crawler
    Route::post('update-master-materials-spreadsheet', AdminUpdateMasterMaterialsSpreadsheetController::class)->name('update.master.materials.spreadsheet');

    //Users
    Route::get('users', AdminUserIndexController::class)->name('users.index');
    Route::get('impersonate/{user}', AdminImpersonationController::class)->name('impersonate');

    //Business
    Route::get('activate-business/{business}', ActivateBusinessController::class)->name('activate.business');

    //Suppliers
    Route::get('/suppliers/{business}', AdminSupplierIndexController::class)->name('suppliers.index');
    Route::post('/suppliers/{business}', AdminSupplierStoreController::class)->name('suppliers.store');
});
