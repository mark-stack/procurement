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
use App\Models\Product;
use App\Models\RawMaterialQuote;
use App\Services\ProductService;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
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
                $selectedProduct["product"],
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
        $validator = Validator::make([], []);
        $validationErrors = 0;
        foreach($request->all() as $index => $row){
            $product = $row["selected"]["product"];
            $material = $row["selected"]["material"];
            $grade = $row["selected"]["grade"];
            $size = $row["selected"]["size"];
            $quantify = $row["selected"]["quantify"];

            //product
            if($product){
                if($product === "Other" && !$row['selected_other']['product']){
                    $validationErrors++;
                    $validator->errors()->add($index."-product", 'product');
                }
            }
            else{
                $validationErrors++;
                $validator->errors()->add($index."-product", 'product');
            }

            //material
            if($material){
                if($material === "Other" && !$row['selected_other']['material']){
                    $validationErrors++;
                    $validator->errors()->add($index."-material", 'material');
                }
            }
            else{
                $validationErrors++;
                $validator->errors()->add($index."-material", 'material');
            }

            //Grade
            if($grade){
                if($grade === "Other" && !$row['selected_other']['grade']){
                    $validationErrors++;
                    $validator->errors()->add($index."-grade", 'grade');
                }
            }
            else{
                $validationErrors++;
                $validator->errors()->add($index."-grade", 'grade');
            }

            //Size
            if(!$size){
                $validationErrors++;
                $validator->errors()->add($index."-size", 'size');
            }

            //Quantify
            if(!$quantify){
                $validationErrors++;
                $validator->errors()->add($index."-quantify", 'quantify');
            }
        }

        //has errors
        if($validationErrors > 0){
            throw new ValidationException($validator);
        }
        else{
            $user = auth()->user();
            $productService = new ProductService();

            foreach($request->all() as $item){

            //  "selected" => array:6 [▼
            //    "product" => "LVL"
            //    "material" => "ALLOY"
            //    "grade" => "NONE"
            //    "size" => 76
            //    "quantify" => "FEET"
            //    "suppliers" => "Other"
            //  ]
            //  "selected_other" => array:6 [▼
            //    "product" => null
            //    "material" => null
            //    "grade" => null
            //    "surface" => null
            //    "quantify" => null
            //    "suppliers" => null
            //  ]
            //  "data" => array:15 [▼
            //    "id" => 487
            //    "created_at" => "2024-12-05T20:33:30.000000Z"
            //    "updated_at" => "2024-12-05T20:33:30.000000Z"
            //    "csv_index" => 27
            //    "description" => "Steel Beams (I-Beams)"
            //    "product_category" => "UB"
            //    "material" => null
            //    "measurement_unit" => "METERS"
            //    "length_required" => "12"
            //    "width_required" => "1"
            //    "sub_qty" => "2"
            //    "unit_rate" => "50"
            //    "project_id" => 2
            //    "general_product_matches" => "a:0:{}"
            //    "product" => null
            //  ]
            //  "subOption" => array:4 [▼
            //    "product" => "all"
            //    "material" => "all"
            //    "grade" => "all"
            //    "suppliers" => "all"
            //  ]

                $product = $item["selected"]["product"] === "Other"
                    ? $item["selected_other"]["product"]
                    : $item["selected"]["product"];
                $material = $item["selected"]["material"] === "Other"
                    ? $item["selected_other"]["material"]
                    : $item["selected"]["material"];
                $grade = $item["selected"]["grade"] === "Other"
                    ? $item["selected_other"]["grade"]
                    : $item["selected"]["grade"];
                $size = $item["selected"]["size"];
                $quantify = $item["selected"]["quantify"];

                //Create item
                $product = Product::create([
                    "spreadsheet_id" => null,
                    "description" => $item["data"]["description"],
                    "product" => $product,
                    "material" => $material,
                    "grade" => $grade,
                    "surface" => SurfaceEnums::NONE->value,
                    "measurement_unit" => $quantify,
                    "size" => $size,
                    "length" => 1, //todo actual?
                    "width" => 1,
                    "kg_per_m" => 0,
                    "baseline_unit_rate" => 0, //todo get quoted price
                    'domain' => $user->getDomainFromEmail(),
                    "deprecated" => false,
                ]);

                $generalProductMatches = Product::select('product', 'material', 'grade', 'surface', 'measurement_unit', 'size')
                        ->distinct()
                        ->availableFor($user)
                        ->where("product", $product)
                        ->where("material", $material)
                        ->where("grade", $grade)
                        ->where("surface", SurfaceEnums::NONE->value)
                        ->where("measurement_unit", $quantify)
                        ->where("size",$size)
                        ->get();

                $rawMaterialQuote = RawMaterialQuote::findOrFail($item["data"]["id"]);
                $rawMaterialQuote->general_product_matches = serialize($generalProductMatches->toArray());
                $rawMaterialQuote->save();
            }

            return back();
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
