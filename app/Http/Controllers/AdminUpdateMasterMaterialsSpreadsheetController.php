<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AdminUpdateMasterMaterialsSpreadsheetController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request)
    {
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
                    $data[] = [
                        "spreadsheet_id" => $row[0],
                        "description" => $row[1],
                        "product" => $row[2],
                        "material" => $row[3],
                        "grade" => $row[4],
                        "surface" => $row[5],
                        "nesting_algo" => $row[6],
                        "certificates" => $row[7],
                        "nominal_units" => $row[8],
                        "nominal_length" => $row[9],
                        "nominal_width" => $row[10],
                        "nominal_height" => $row[11],
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
                    "nesting_algo" => $spreadsheetRowData["nesting_algo"],
                    "certificates" => $spreadsheetRowData["certificates"],
                    "nominal_units" => $spreadsheetRowData["nominal_units"],
                    "nominal_length" => $spreadsheetRowData["nominal_length"],
                    "nominal_width" => $spreadsheetRowData["nominal_width"],
                    "nominal_height" => $spreadsheetRowData["nominal_height"],
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
                "nesting_algo" => $spreadsheetRowData["nesting_algo"],
                "certificates" => $spreadsheetRowData["certificates"],
                "nominal_units" => $spreadsheetRowData["nominal_units"],
                "nominal_length" => $spreadsheetRowData["nominal_length"],
                "nominal_width" => $spreadsheetRowData["nominal_width"],
                "nominal_height" => $spreadsheetRowData["nominal_height"],
                "kg_per_m" => $spreadsheetRowData["kg_per_m"],
                "baseline_unit_rate" => $spreadsheetRowData["baseline_unit_rate"],
                'business_id' => null,
                "deprecated" => false,
            ]);
        }

        dd("done");
    }
}
