<?php

use App\Http\Controllers\TemplateController;
use App\Http\Middleware\AdminMiddleware;
use App\Models\Product;
use App\Models\Template;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

Route::prefix("admin")->name("admin.")->middleware([AdminMiddleware::class])->group(function () {
    Route::get('/dashboard', function () {
        return Inertia::render('AdminDashboard',[
            "templates" => Template::all()
        ]);
    })->name("dashboard");

    //Templates
    Route::resource('templates', TemplateController::class);

    //Update master materials spreadsheet
    Route::get("update-master-materials-spreadsheet",function(){

        $filePath = 'master_materials.csv';
        if (!Storage::exists($filePath)) {
            return "materials.csv not found! Check 'app/private'";
        }

        // Read the CSV
        $data = [];
        if (($handle = fopen(storage_path("app/private/{$filePath}"), 'r')) !== false) {
            while (($row = fgetcsv($handle, 1000, ',')) !== false) {
                if($row[0] !== ""){
                    $data[] = [
                        "description" => $row[0],
                        "product" => $row[1],
                        "material" => $row[2],
                        "grade" => $row[3],
                        "surface" => $row[4],
                        "measurement_unit" => $row[5],
                        "size" => $row[6],
                        "length" => $row[7],
                        "width" => $row[8],
                        "kg_per_m" => $row[9],
                        "baseline_unit_rate" => $row[10],
                    ];
                }
            }
            fclose($handle);
        }

        unset($data[0]);

        $dataCollection = collect($data);

        /**
         * Create, Deprecate, Delete
         *   - Create: if doesn't exists
         *   - Deprecate: if has been used
         *   - Delete: if not used anywhere
         */
        //Deprecate & delete
        $allCurrentMasterProductRecords = Product::query()
            ->platformCreated()
            ->active()
            ->get();

        $allCurrentMasterProductRecordsIds = $allCurrentMasterProductRecords->pluck("description");

        foreach($allCurrentMasterProductRecords as $productObject){
            $productData = $dataCollection->where("description",$productObject->description);

            //Is still in the spreadsheet (unchanged)
            if($productData){
                //No action required.
            }
            //Is NOT in the spreadsheet (has been deleted)
            else{
                //Has been used
                $projectsWithThisProduct = $productObject->projects()->count() > 0;
                if($projectsWithThisProduct){
                    //Deprecate
                    $productObject->deprecated = true;
                    $productObject->save();
                }
                //Has NOT been used
                else{
                    //Delete
                    $productObject->delete();
                }
            }
        }

        //Create
        $productsNotYetCreated = $dataCollection
            ->whereNotIn("description",$allCurrentMasterProductRecordsIds);

        foreach($productsNotYetCreated as $productData){
            Product::create([
                "description" => $productData["description"],
                "product" => $productData["product"],
                "material" => $productData["material"],
                "grade" => $productData["grade"],
                "surface" => $productData["surface"],
                "measurement_unit" => $productData["measurement_unit"],
                "size" => $productData["size"],
                "length" => $productData["length"],
                "width" => $productData["width"],
                "kg_per_m" => $productData["kg_per_m"],
                "baseline_unit_rate" => $productData["baseline_unit_rate"],
                'domain' => null,
                "deprecated" => false,
            ]);
        }

        dd("done");
    })->name("update.master.materials.spreadsheet");
});
