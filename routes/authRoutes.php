<?php

use App\Enums\GradeEnums;
use App\Enums\MaterialEnums;
use App\Enums\MeasurementUnitEnums;
use App\Enums\ProductEnums;
use App\Enums\SurfaceEnums;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\RawMaterialQuoteController;
use App\Models\RawMaterialQuote;
use App\Services\ProductService;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Illuminate\Http\Request;

Route::middleware(['auth'])->group(function () {
    //Projects
    Route::resource('projects', ProjectController::class);

    //Products
    Route::controller(ProductController::class)->group(function () {
        Route::get("/{project}/products",'index')->name("products.index"); //GET	/photos	index	photos.index
        //Route::get('/products/{id}', 'products.show')->name("products.show"); //GET	/photos/{photo}	show	photos.show
        Route::post('/{project}/products', 'store')->name("products.store"); //POST	/photos	store	photos.store
    });
    //GET	/photos/create	create	photos.create
    //GET	/photos/{photo}/edit	edit	photos.edit
    //PUT/PATCH	/photos/{photo}	update	photos.update
    //DELETE	/photos/{photo}	destroy	photos.destroy


    //Raw Material Quotes
    Route::post("raw-material-quote-bulk-destroy",function(Request $request){
        $ids = $request->selectedRawMaterialQuoteIds;
        RawMaterialQuote::query()
            ->whereIn("id",$ids)
            ->delete();

        return back();
    })->name("raw.material.quote.bulk.destroy");

    //Raw Material Quote clarifications
    Route::post("raw-material-quote-clarifications",function(Request $request){
        $user = auth()->user();
        $productService = new ProductService();

        foreach($request->all() as $item){
            $selectedProduct = $item["options"][$item["selected"]];
//            "product" => "PFC"
//            "material" => "STEEL"
//            "grade" => "GR300"
//            "surface" => "NONE"
//            "measurement_unit" => "METERS"
//            "size" => "100"

            $rawMaterialQuote = RawMaterialQuote::findOrFail($item["data"]["id"]);

            $generalProductMatches = $productService->findGeneralProductMatches(
                $user,
                ProductEnums::from($selectedProduct["product"]),
                MaterialEnums::from($selectedProduct["material"]),
                [GradeEnums::from($selectedProduct["grade"])],
                SurfaceEnums::from($selectedProduct["surface"]),
                MeasurementUnitEnums::from($selectedProduct["measurement_unit"]),
                $selectedProduct["size"],
                $selectedProduct["length"] ?? null,
            );
            if($generalProductMatches->count() === 1){
                $rawMaterialQuote->general_product_matches = serialize($generalProductMatches);
                $rawMaterialQuote->save();
            }
        }

        return back();
    })->name("raw.material.quote.clarifications");

    Route::post("raw-material-quote-customisations",function(Request $request){
        $validationErrors = [];
        foreach($request->all() as $index => $row){
            $product = $row["selected"]["product"];
            $material = $row["selected"]["material"];
            $grade = $row["selected"]["grade"];
            $hasAllFields = $product && $material && $grade;
            if(!$hasAllFields){
                if(!$product){
                    $validationErrors[$index][] = "product";
                }
                if(!$material){
                    $validationErrors[$index][] = "material";
                }
                if(!$grade){
                    $validationErrors[$index][] = "grade";
                }
            }
        }

        if(count($validationErrors) > 0){
            //todo throw error with array for the view to find errors
            dd($validationErrors);
        }
        else{
            $user = auth()->user();
            $productService = new ProductService();
            dd($request->all());

            foreach($request->all() as $item){
                dd($item);
            }
        }
    })->name("raw.material.quote.customisations");

    Route::controller(RawMaterialQuoteController::class)->group(function () {
        Route::delete('/raw-material-quote/{rawMaterialQuote}', 'destroy')->name("raw.material.quote.destroy"); //DELETE /photos/{photo}	destroy	photos.destroy
    });

    //Dashboard
    Route::get('/dashboard', function () {
        $user = auth()->user();

        return Inertia::render('Dashboard',[
            "projects" => $user->projects,
        ]);
    })->middleware(['auth', 'verified'])->name('dashboard');

    //Profile
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});
