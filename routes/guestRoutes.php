<?php

use App\Formatters\NestingFormatter;
use App\Http\Controllers\GuestOnboardingController;
use App\Http\Controllers\LandingController;
use App\Http\Controllers\NestingExampleController;
use App\Models\Business;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

//Landing page
Route::get('/', LandingController::class);

//Guest onboarding
Route::get("guest-onboarding", GuestOnboardingController::class)->name("guest.onboarding");

//Try nesting
Route::get('/try-nesting', NestingExampleController::class)->name("try.nesting");

