<?php

namespace App\Jobs;

use App\Models\Product;
use App\Models\User;
use App\Notifications\AdminImportFinalised;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

class AdminMaterialsImport implements ShouldQueue
{
    use Queueable;

    public Collection $dataCollection;

    public function __construct($dataCollection)
    {
        $this->dataCollection = $dataCollection;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        /**
         * Create or Deprecate
         */

        //Deprecate all platform-created products by default then "un-deprecate" all items found in current spreadsheet
        Product::query()
            ->platformCreated()
            ->update([
                "deprecated" => true,
            ]);

        //Create new products or update back to deprecated=false
        foreach($this->dataCollection as $row){
            Product::updateOrCreate([
                'business_id' => null,
                "description" => $row["description"],
                "product_category" => $row["product_category"],
                "material" => $row["material"],
                "grade" => $row["grade"],
                "surface" => $row["surface"],
                "nesting_algo" => $row["nesting_algo"],
                "certificates" => $row["certificates"],
                "nominal_units" => $row["nominal_units"],
                "nominal_length" => $row["nominal_length"],
                "actual_length" => $row["actual_length"],
                "nominal_width" => $row["nominal_width"],
                "actual_width" => $row["actual_width"],
                "nominal_height" => $row["nominal_height"],
                "actual_height" => $row["actual_height"],
                "wall" => $row["wall"] === "" ? null : (float) $row["wall"],
                "kg_per_m" => $row["kg_per_m"],
                "baseline_unit_rate" => $row["baseline_unit_rate"],
                "pack_size_1" => $row["pack_size_1"],
                "pack_size_2" => $row["pack_size_2"],
                "pack_size_3" => $row["pack_size_3"],
            ],
            [
                "deprecated" => false,
            ]);
        }

        /**
         * Send completion email
         */
        $adminUser = User::query()->where("email",env("ADMIN_EMAIL"))->first();
        if($adminUser){
            $message = "The import finalised.";
            Notification::send($adminUser, new AdminImportFinalised($message));
        }
    }
}
