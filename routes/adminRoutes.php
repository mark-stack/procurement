<?php

use App\Http\Controllers\TemplateController;
use App\Http\Middleware\AdminMiddleware;
use App\Models\Template;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::prefix("admin")->name("admin.")->middleware([AdminMiddleware::class])->group(function () {
    Route::get('/dashboard', function () {
        return Inertia::render('AdminDashboard',[
            "templates" => Template::all()
        ]);
    })->name("dashboard");

    //Templates
    Route::resource('templates', TemplateController::class);
});
