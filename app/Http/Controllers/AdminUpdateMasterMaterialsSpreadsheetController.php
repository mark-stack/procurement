<?php

namespace App\Http\Controllers;

use App\Jobs\AdminMaterialsImport;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AdminUpdateMasterMaterialsSpreadsheetController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request): string
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
                        "precise_length" => $row[9],
                        "nominal_width" => $row[10],
                        "precise_width" => $row[11],
                        "nominal_height" => $row[12],
                        "precise_height" => $row[13],
                        "wall" => $row[14],
                        "pack_size_1" => $row[15],
                        "pack_size_2" => $row[16],
                        "pack_size_3" => $row[17],
                        "kg_per_m" => $row[18],
                        "baseline_unit_rate" => $row[19],
                    ];
                }
            }
            fclose($handle);
        }

        //Remove heading row
        unset($data[0]);

        $dataCollection = collect($data);

        AdminMaterialsImport::dispatchSync($dataCollection);

        return "Done";
    }
}
