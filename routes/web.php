<?php

//todo: experimental
use App\Models\Product;
use App\Models\User;
use App\Services\ProductService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

//todo temporary
Route::get("pickles",function(){
    $admin = User::query()
        ->where("email",env('ADMIN_EMAIL'))
        ->firstOrFail();

    Auth::login($admin);

    return redirect()->route("admin.dashboard");
});

//todo temporary
Route::get("test",function(){
    $description = "20PL 350 MPA";

    $productService = new ProductService();

    //PRODUCT
    $product = $productService->findProduct($description);

    //Has product
    if($product){
        //MATERIAL
        $materialEnum = $productService->findMaterial($product);

        //GRADE
        $gradesEnums = $productService->findGrades($product,$description);

        //SURFACE
        $surfaceEnum = $productService->findSurface($product,$description,$gradesEnums);

        //MEASUREMENT_UNIT
        $measurementUnitEnum = $productService->findMeasurementUnit($product);

        //SIZE
        $sizeInt = $productService->findSize($product,$description);

        //LENGTH
        $lengthInt = $productService->findLength($product,$description);

        //Price book search
        $user = auth()->user();
        $generalProductMatches = $productService->findGeneralProductMatches(
            $user,
            $product["productEnum"]->value,
            $materialEnum,
            $gradesEnums,
            $surfaceEnum,
            $measurementUnitEnum,
            $sizeInt,
            $lengthInt,
        );

//        $priceBookProducts = $productService->findByAttributes(
//            $user,
//            $product["productEnum"],
//            $material,
//            $grades,
//            $surface,
//            $measurementUnit,
//            $size,
//            $length
//        );


        dd([
            "description" => $description,
            "product" => $product,
            "material" => $materialEnum,
            "grades" => $gradesEnums,
            "surface" => $surfaceEnum,
            "measurementUnit" => $measurementUnitEnum,
            "size" => $sizeInt,
            "length" => $lengthInt,
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
