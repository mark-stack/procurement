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
                        "description" => $row[0],
                        "product_category" => $row[1],
                        "material" => $row[2],
                        "grade" => $row[3],
                        "surface" => $row[4],
                        "nesting_algo" => $row[5],
                        "certificates" => $row[6],
                        "nominal_units" => $row[7],
                        "nominal_length" => $row[8],
                        "nominal_width" => $row[9],
                        "nominal_height" => $row[10],
                        "pack_size_1" => $row[11],
                        "pack_size_2" => $row[12],
                        "pack_size_3" => $row[13],
                        "kg_per_m" => $row[14],
                        "baseline_unit_rate" => $row[15],
                    ];
                }
            }
            fclose($handle);
        }

        //Remove heading row
        unset($data[0]);

        $dataCollection = collect($data);

        /**
         * Create or Deprecate
         *   1) Create: If it doesn't exist
         *   2) Deprecate: not present in master sheet anymore
         */

        $added = [];
        $deprecated = [];

        /*
         * 1) Create: If it doesn't exist
         * Loop spreadsheet looking for DB matches
         */
        $allCurrentMasterProductRecords = Product::query()
            ->platformCreated()
            ->active()
            ->get();
        foreach($dataCollection as $index => $row){
            $productId = $this->findDatabaseRowToMatchSpreadsheetRow($row,$allCurrentMasterProductRecords);

            //Spreadsheet row is NOT in the database
            if(!$productId){
                //Create
                Product::create([
                    "description" => $row["description"],
                    "product_category" => $row["product_category"],
                    "material" => $row["material"],
                    "grade" => $row["grade"],
                    "surface" => $row["surface"],
                    "nesting_algo" => $row["nesting_algo"],
                    "certificates" => $row["certificates"],
                    "nominal_units" => $row["nominal_units"],
                    "nominal_length" => $row["nominal_length"],
                    "nominal_width" => $row["nominal_width"],
                    "nominal_height" => $row["nominal_height"],
                    "pack_size_1" => $row["pack_size_1"],
                    "pack_size_2" => $row["pack_size_2"],
                    "pack_size_3" => $row["pack_size_3"],
                    "kg_per_m" => $row["kg_per_m"],
                    "baseline_unit_rate" => $row["baseline_unit_rate"],
                    'business_id' => null,
                    "deprecated" => false,
                ]);

                $added[] = $row["description"];
            }
        }

        /*
         * 2) Deprecate: not present in master sheet anymore
         * Loop DB looking for spreadsheet matches
         */
        foreach($allCurrentMasterProductRecords as $productObject){
            $spreadsheetIndex = $this->findSpreadsheetRowToMatchDatabaseRow($productObject,$dataCollection);

            //Database row is NOT in the spreadsheet
            if(!$spreadsheetIndex){
                $productObject->deprecated = true;
                $productObject->save();

                $deprecated[] = $productObject->description;
            }
        }

        return [
            "added" => $added,
            "deprecated" => $deprecated,
        ];
    }

    public function findSpreadsheetRowToMatchDatabaseRow(Product $productObject, object $dataCollection): ?int
    {
        $matchedIndex = null;
        foreach($dataCollection as $index => $row){
            $description = strtoupper($row["description"]) === strtoupper($productObject->description);
            $product = strtoupper($row["product"]) === strtoupper($productObject->product);
            $material = strtoupper($row["material"]) === strtoupper($productObject->material);
            $grade = strtoupper($row["grade"]) === strtoupper($productObject->grade);
            $surface = strtoupper($row["surface"]) === strtoupper($productObject->surface);
            $nesting_algo = strtoupper($row["nesting_algo"]) === strtoupper($productObject->nesting_algo);
            $certificates = strtoupper($row["certificates"]) === strtoupper($productObject->certificates);
            $nominal_units = strtoupper($row["nominal_units"]) === strtoupper($productObject->nominal_units);
            $nominal_length = strtoupper($row["nominal_length"]) === strtoupper($productObject->nominal_length);
            $nominal_width = strtoupper($row["nominal_width"]) === strtoupper($productObject->nominal_width);
            $nominal_height = strtoupper($row["nominal_height"]) === strtoupper($productObject->nominal_height);
            $kg_per_m = strtoupper($row["kg_per_m"]) === strtoupper($productObject->kg_per_m);
            $baseline_unit_rate = strtoupper($row["baseline_unit_rate"]) === strtoupper($productObject->baseline_unit_rate);

            if(
                $description &&
                $product &&
                $material &&
                $grade &&
                $surface &&
                $nesting_algo &&
                $certificates &&
                $nominal_units &&
                $nominal_length &&
                $nominal_width &&
                $nominal_height &&
                $kg_per_m &&
                $baseline_unit_rate
            ){
                $matchedIndex = $index;
            }
        }

        return $matchedIndex;
    }

    public function findDatabaseRowToMatchSpreadsheetRow(array $row, object $allCurrentMasterProductRecords): ?int
    {
        $record = $allCurrentMasterProductRecords
            ->where("description",$row["description"])
            ->where("product_category",$row["product_category"])
            ->where("material",$row["material"])
            ->where("grade",$row["grade"])
            ->where("surface",$row["surface"])
            ->where("nesting_algo",$row["nesting_algo"])
            ->where("certificates",$row["certificates"])
            ->where("nominal_units",$row["nominal_units"])
            ->where("nominal_length",$row["nominal_length"])
            ->where("nominal_width",$row["nominal_width"])
            ->where("nominal_height",$row["nominal_height"])
            ->where("kg_per_m",$row["kg_per_m"])
            ->where("baseline_unit_rate",$row["baseline_unit_rate"])
            ->first();

        return $record ? $record->id : null;
    }
}
