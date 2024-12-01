<?php

//todo: experimental
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

Route::get("test",function(){
    $text = "PFC MS 12m";

    $productService = new ProductService();

    //PRODUCT
    $product = $productService->findProduct($text);

    //Has product
    if($product){
        //MATERIAL
        $material = $productService->findMaterial($product);

        //GRADE
        $grade = $productService->findGrade($product,$text);

        //SURFACE
        $surface = $productService->findSurface($product,$text,$grade);

        //MEASUREMENT_UNIT
        $measurementUnit = $productService->findMeasurementUnit($product,$text);

        //SIZE
        $size = $productService->findSize($product,$text);

        //LENGTH
        $length = $productService->findLength($product,$text);

        dd([
            "text" => $text,
            "product" => $product,
            "material" => $material,
            "grade" => $grade,
            "surface" => $surface,
            "measurementUnit" => $measurementUnit,
            "size" => $size,
            "length" => $length,
        ]);
    }
    //NO product found
    else{
        dd("No product found",$text);
    }
});

require __DIR__.'/auth.php';
require __DIR__.'/authRoutes.php';
require __DIR__.'/guestRoutes.php';
require __DIR__.'/adminRoutes.php';
