<?php

//todo: experimental
use App\Models\Project;
use App\Models\User;
use App\Services\DataClassificationService;
use App\Services\NestingService;
use App\Services\NotificationService;
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

    $description = "M12 PB1230 30";

    $productService = new ProductService();
    $dataClassificationService = new DataClassificationService();

    //PRODUCT
    $product = $dataClassificationService->findProduct($description,null);

    //Has product
    if($product){
        //MATERIAL
        $materialEnum = $dataClassificationService->findMaterial($product,$description);

        //GRADE
        $gradesEnums = $dataClassificationService->findGrades($product,$description);

        //SURFACE
        $surfaceEnum = $dataClassificationService->findSurface($product,$description,$gradesEnums);

        //NOMINAL UNITS
        $measurementUnitEnum = $dataClassificationService->findMeasurementUnit($product);

        //NOMINAL LENGTH
        $nominalLengthInt = $dataClassificationService->findNominal($product,$description,"nominalLengthRegex");

        //NOMINAL WIDTH
        $nominalWidthInt = $dataClassificationService->findNominal($product,$description,"nominalWidthRegex");

        //NOMINAL HEIGHT
        $nominalHeightInt = $dataClassificationService->findNominal($product,$description,"nominalHeightRegex");


        //Price book search
        $user = auth()->user();
        $generalProductMatches = $dataClassificationService->findGeneralProductMatches(
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
