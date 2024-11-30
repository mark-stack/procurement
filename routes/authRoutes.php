<?php

use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProjectController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware(['auth'])->group(function () {
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

    //Dashboard
    Route::get('/dashboard', function () {
        $user = auth()->user();

        return Inertia::render('Dashboard',[
            "projects" => $user->projects,
        ]);
    })->middleware(['auth', 'verified'])->name('dashboard');

    //Profile
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});
