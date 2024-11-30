<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Illuminate\Foundation\Application;

Route::middleware(['guest'])->group(function () {
    //Landing page
    Route::get('/', function () {
        return Inertia::render('Welcome', [
            'canLogin' => Route::has('login'),
            'canRegister' => Route::has('register'),
            'laravelVersion' => Application::VERSION,
            'phpVersion' => PHP_VERSION,
        ]);
    });
});
