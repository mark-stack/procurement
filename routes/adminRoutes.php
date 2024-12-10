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

                //Skip blank rows
                if($row[0] !== ""){

                    $nestingAlgo = $row[7];
                    if($nestingAlgo === "LINEAR"){
                        $nestingAlgo = "METERAGE";
                    }
                    if($nestingAlgo === "PACK"){
                        $nestingAlgo = "BUNDLE";
                    }

                    $data[] = [
                        "spreadsheet_id" => $row[0],
                        "description" => $row[1],
                        "product" => $row[2],
                        "material" => $row[3],
                        "grade" => $row[4],
                        "surface" => $row[5],
                        "measurement_unit" => $row[6],
                        "nesting_algo" => $nestingAlgo,
                        "certificates" => $row[8],
                        "size" => $row[9],
                        "length" => $row[10],
                        "width" => $row[11],
                        "kg_per_m" => $row[12],
                        "baseline_unit_rate" => $row[13],
                    ];
                }
            }
            fclose($handle);
        }

        //Remove heading row
        unset($data[0]);

        $dataCollection = collect($data);

        /**
         * Update, Create, Deprecate
         *   1) Update: if exists
         *   2) Create: if doesn't exists
         *   3) Deprecate: not present in master sheet anymore
         */
        $allCurrentMasterProductRecords = Product::query()
            ->platformCreated()
            ->get();

        $allCurrentMasterProductRecordSpreadsheetIds = $allCurrentMasterProductRecords->pluck("spreadsheet_id");

        foreach($allCurrentMasterProductRecords as $productObject){
            $spreadsheetRowData = $dataCollection->where("spreadsheet_id",$productObject->spreadsheet_id)->first();

            /**
             * 1) Update: if exists
             */
            if($spreadsheetRowData){
                $productObject->update([
                    "description" => $spreadsheetRowData["description"],
                    "product" => $spreadsheetRowData["product"],
                    "material" => $spreadsheetRowData["material"],
                    "grade" => $spreadsheetRowData["grade"],
                    "surface" => $spreadsheetRowData["surface"],
                    "measurement_unit" => $spreadsheetRowData["measurement_unit"],
                    "nesting_algo" => $spreadsheetRowData["nesting_algo"],
                    "certificates" => $spreadsheetRowData["certificates"],
                    "size" => $spreadsheetRowData["size"],
                    "length" => $spreadsheetRowData["length"],
                    "width" => $spreadsheetRowData["width"],
                    "kg_per_m" => $spreadsheetRowData["kg_per_m"],
                    "baseline_unit_rate" => $spreadsheetRowData["baseline_unit_rate"],
                ]);
            }
            /**
             * 3) Deprecate: not present in master sheet anymore
             */
            else{
                $productObject->deprecated = true;
                $productObject->save();
            }
        }

        /**
         * 2) Create: if doesn't exists
         */
        $productsNotYetCreated = $dataCollection->whereNotIn("spreadsheet_id",$allCurrentMasterProductRecordSpreadsheetIds);

        foreach($productsNotYetCreated as $spreadsheetRowData){
            Product::create([
                "spreadsheet_id" => $spreadsheetRowData["spreadsheet_id"],
                "description" => $spreadsheetRowData["description"],
                "product" => $spreadsheetRowData["product"],
                "material" => $spreadsheetRowData["material"],
                "grade" => $spreadsheetRowData["grade"],
                "surface" => $spreadsheetRowData["surface"],
                "measurement_unit" => $spreadsheetRowData["measurement_unit"],
                "nesting_algo" => $spreadsheetRowData["nesting_algo"],
                "certificates" => $spreadsheetRowData["certificates"],
                "size" => $spreadsheetRowData["size"],
                "length" => $spreadsheetRowData["length"],
                "width" => $spreadsheetRowData["width"],
                "kg_per_m" => $spreadsheetRowData["kg_per_m"],
                "baseline_unit_rate" => $spreadsheetRowData["baseline_unit_rate"],
                'domain' => null,
                "deprecated" => false,
            ]);
        }

        dd("done");
    })->name("update.master.materials.spreadsheet");
});
