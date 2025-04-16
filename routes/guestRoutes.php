<?php

use App\Formatters\NestingFormatter;
use App\Models\Business;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

//Landing page
Route::get('/', function () {
    //Formatter
    $nestingFormatter = new NestingFormatter();

    $sampleBusiness = Business::query()
        ->where('name',"SAMPLE")
        ->where('domain','sample.com')
        ->first();

    $sampleData = null;
    if($sampleBusiness){
        $sampleData = $nestingFormatter->nestingViewData('SUGGESTED', $sampleBusiness, null);
    }

    return Inertia::render('Welcome', [
        "sampleNestingData" => $sampleData,
    ]);
});

Route::get("guest-onboarding",function(){
    return Inertia::render('GuestOnboarding', [
        //
    ]);
})->name("guest.onboarding");

//Try nesting
Route::get('/try-nesting', function () {
    //Formatter
    $nestingFormatter = new NestingFormatter();

    /*
     * Get sample nesting data
     */

    $sampleBusiness = Business::query()
        ->where('name',"SAMPLE")
        ->where('domain','sample.com')
        ->first();

    $sampleData = $nestingFormatter->nestingViewData('SUGGESTED', $sampleBusiness, null);

    return Inertia::render('TryNesting', [
        "sampleNestingData" => $sampleData
    ]);
})->name("try.nesting");

