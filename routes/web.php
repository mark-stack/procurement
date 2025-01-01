<?php

//todo: experimental
use App\Models\Project;
use App\Models\User;
use App\Services\DataClassificationService;
use App\Services\NestingService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

//todo temporary
Route::get("pickles",function(){
    $admin = User::query()
        ->where("email",env('ADMIN_EMAIL'))
        ->firstOrFail();

    Auth::login($admin);

    return redirect()->route("admin.users.index");
});

//todo temporary
Route::get("stock-cutting",function(){
    // Example Usage:
    $cutLengths = [1000,3000,4000,1000,5000,11000,4000,2000,2500,5000,13000];
    $stockLengths = [9000,12000];

    $result = (new NestingService())->meterageAlgorithm($cutLengths, $stockLengths);

    dd($result);
});
//todo temporary
Route::get("test",function(){
    $description = "CHS193.7*6.0";

    $dataClassificationService = new DataClassificationService();

    $generalProductMatches = $dataClassificationService->findGeneralProductMatchesFromText($description,auth()->user());
    dd($description,$generalProductMatches);
});

require __DIR__.'/auth.php';
require __DIR__.'/authRoutes.php';
require __DIR__.'/guestRoutes.php';
require __DIR__.'/adminRoutes.php';
