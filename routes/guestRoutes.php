<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

//Landing page
Route::get('/', function () {
    return Inertia::render('Welcome', [

    ]);
});
