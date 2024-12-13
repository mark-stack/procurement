<?php

use App\Http\Controllers\ActivateBusinessController;
use App\Http\Controllers\AdminImpersonationController;
use App\Http\Controllers\AdminSupplierIndexController;
use App\Http\Controllers\AdminUpdateMasterMaterialsSpreadsheetController;
use App\Http\Controllers\AdminUserIndexController;
use App\Http\Controllers\TemplateController;
use App\Http\Middleware\AdminMiddleware;
use Illuminate\Support\Facades\Route;

Route::prefix("admin")->name("admin.")->middleware([AdminMiddleware::class])->group(function () {
    //Templates
    Route::controller(TemplateController::class)->group(function () {
        Route::get("/templates/{business}",'index')->name("templates.index"); //GET	/photos	index	photos.index
        //Route::get('/products/{id}', 'products.show')->name("products.show"); //GET	/photos/{photo}	show	photos.show
        Route::post('/templates/{business}', 'store')->name("templates.store"); //POST	/photos	store	photos.store
        Route::put('/templates/{template}/{business}', 'update')->name("templates.update"); //PUT/PATCH  /photos/{photo}	update	photos.update
        Route::delete('/templates/{template}', 'destroy')->name("templates.destroy"); //DELETE	/photos/{photo}	destroy	photos.destroy
    });
    //GET	/photos/create	create	photos.create
    //GET	/photos/{photo}/edit	edit	photos.edit


    //Update master materials spreadsheet
    Route::get("update-master-materials-spreadsheet", AdminUpdateMasterMaterialsSpreadsheetController::class)->name("update.master.materials.spreadsheet");

    //Users
    Route::get("users", AdminUserIndexController::class)->name("users.index");
    Route::get("impersonate/{user}", AdminImpersonationController::class)->name("impersonate");

    //Business
    Route::get("activate-business/{business}", ActivateBusinessController::class)->name("activate.business");

    //Suppliers
    Route::get("/suppliers/{business}", AdminSupplierIndexController::class)->name("suppliers.index");
});
