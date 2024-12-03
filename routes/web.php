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
    $description = "20mm plate GR250";

    $productService = new ProductService();

    //PRODUCT
    $product = $productService->findProduct($description);

    //Has product
    if($product){
        //MATERIAL
        $material = $productService->findMaterial($product);

        //GRADE
        $grades = $productService->findGrades($product,$description);

        //SURFACE
        $surface = $productService->findSurface($product,$description,$grades);

        //MEASUREMENT_UNIT
        $measurementUnit = $productService->findMeasurementUnit($product);

        //SIZE
        $size = $productService->findSize($product,$description);

        //LENGTH
        $length = $productService->findLength($product,$description);

        //Price book search
        $user = auth()->user();
        $priceBookProducts = $productService->findByAttributes(
            $user,
            $product["productEnum"],
            $material,
            $grades,
            $surface,
            $measurementUnit,
            $size,
            $length
        );


        dd([
            "description" => $description,
            "product" => $product,
            "material" => $material,
            "grades" => $grades,
            "surface" => $surface,
            "measurementUnit" => $measurementUnit,
            "size" => $size,
            "length" => $length,
            "priceBookProducts" => $priceBookProducts,
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
