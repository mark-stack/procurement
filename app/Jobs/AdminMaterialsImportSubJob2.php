<?php

namespace App\Jobs;

use App\Models\Product;
use App\Models\User;
use App\Notifications\AdminImportFinalised;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * @deprecated
 */
class AdminMaterialsImportSubJob2 implements ShouldQueue
{
    use Queueable;

    public Product $productObject;
    public Collection $dataCollection;

    public function __construct($productObject,$dataCollection)
    {
        $this->productObject = $productObject;
        $this->dataCollection = $dataCollection;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        /*
         * Deprecate: not present in master sheet anymore
         * Loop DB looking for spreadsheet matches
         */

        $spreadsheetIndex = $this->findSpreadsheetRowToMatchDatabaseRow($this->productObject,$this->dataCollection);

        //Database row is NOT in the spreadsheet
        if(!$spreadsheetIndex){
            $this->productObject->deprecated = true;
            $this->productObject->save();
        }
    }

    private function findSpreadsheetRowToMatchDatabaseRow(Product $productObject, object $dataCollection): ?int
    {
        $matchedIndex = null;
        foreach($dataCollection as $index => $row){
            $description = strtoupper($row["description"]) === strtoupper($productObject->description);
            $product = strtoupper($row["product_category"]) === strtoupper($productObject->product_category);
            $material = strtoupper($row["material"]) === strtoupper($productObject->material);
            $grade = strtoupper($row["grade"]) === strtoupper($productObject->grade);
            $surface = strtoupper($row["surface"]) === strtoupper($productObject->surface);
            $nesting_algo = strtoupper($row["nesting_algo"]) === strtoupper($productObject->nesting_algo);
            $certificates = strtoupper($row["certificates"]) === strtoupper($productObject->certificates);
            $nominal_units = strtoupper($row["nominal_units"]) === strtoupper($productObject->nominal_units);
            $nominal_length = strtoupper($row["nominal_length"]) === strtoupper($productObject->nominal_length);
            $actual_length = strtoupper($row["actual_length"]) === strtoupper($productObject->actual_length);
            $nominal_width = strtoupper($row["nominal_width"]) === strtoupper($productObject->nominal_width);
            $actual_width = strtoupper($row["actual_width"]) === strtoupper($productObject->actual_width);
            $nominal_height = strtoupper($row["nominal_height"]) === strtoupper($productObject->nominal_height);
            $actual_height = strtoupper($row["actual_height"]) === strtoupper($productObject->actual_height);
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
                $actual_length &&
                $nominal_width &&
                $actual_width &&
                $nominal_height &&
                $actual_height &&
                $kg_per_m &&
                $baseline_unit_rate
            ){
                $matchedIndex = $index;
            }
        }

        return $matchedIndex;
    }
}
