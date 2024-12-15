<?php

//todo: experimental
use App\Models\Product;
use App\Models\User;
use App\Services\NestingService;
use App\Services\ProductService;
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
    $description = "PFC SS316";

    $productService = new ProductService();

    //PRODUCT
    $product = $productService->findProduct($description);

    //Has product
    if($product){
        //MATERIAL
        $materialEnum = $productService->findMaterial($product,$description);

        //GRADE
        $gradesEnums = $productService->findGrades($product,$description);

        //SURFACE
        $surfaceEnum = $productService->findSurface($product,$description,$gradesEnums);

        //NOMINAL UNITS
        $measurementUnitEnum = $productService->findMeasurementUnit($product);

        //NOMINAL LENGTH
        $nominalLengthInt = $productService->findNominal($product,$description,"nominalLengthRegex");

        //NOMINAL WIDTH
        $nominalWidthInt = $productService->findNominal($product,$description,"nominalWidthRegex");

        //NOMINAL HEIGHT
        $nominalHeightInt = $productService->findNominal($product,$description,"nominalHeightRegex");


        //Price book search
        $user = auth()->user();
        $generalProductMatches = $productService->findGeneralProductMatches(
            $user,
            $product["productEnum"]->value,
            $materialEnum,
            $gradesEnums,
            $surfaceEnum,
            $measurementUnitEnum,
            $nominalLengthInt,
            $nominalWidthInt,
            $nominalHeightInt
        );

        dd([
            "description" => $description,
            "product" => $product,
            "material" => $materialEnum,
            "grades" => $gradesEnums,
            "surface" => $surfaceEnum,
            "measurementUnit" => $measurementUnitEnum,
            "nominalLengthInt " => $nominalLengthInt,
            "nominalWidthInt" => $nominalWidthInt,
            "nominalHeightInt" => $nominalHeightInt,
            "generalProductMatches" => $generalProductMatches,
        ]);
    }
    //NO product found
    else{
        dd("No product found",$product);
    }
});

require __DIR__.'/auth.php';
require __DIR__.'/authRoutes.php';
require __DIR__.'/guestRoutes.php';
require __DIR__.'/adminRoutes.php';
