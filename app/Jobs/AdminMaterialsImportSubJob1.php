<?php

namespace App\Jobs;

use App\Models\Product;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * @deprecated
 */
class AdminMaterialsImportSubJob1 implements ShouldQueue
{
    use Queueable;

    public array $row;

    public object $allCurrentMasterProductRecords;

    public function __construct($row, $allCurrentMasterProductRecords)
    {
        $this->row = $row;
        $this->allCurrentMasterProductRecords = $allCurrentMasterProductRecords;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $productId = $this->findDatabaseRowToMatchSpreadsheetRow($this->row, $this->allCurrentMasterProductRecords);

        //Spreadsheet row is NOT in the database
        if (! $productId) {
            //Create
            Product::create([
                'description' => $this->row['description'],
                'product_category' => $this->row['product_category'],
                'material' => $this->row['material'],
                'grade' => $this->row['grade'],
                'surface' => $this->row['surface'],
                'nesting_algo' => $this->row['nesting_algo'],
                'certificates' => $this->row['certificates'],
                'nominal_units' => $this->row['nominal_units'],
                'nominal_length' => $this->row['nominal_length'],
                'actual_length' => $this->row['actual_length'],
                'nominal_width' => $this->row['nominal_width'],
                'actual_width' => $this->row['actual_width'],
                'nominal_height' => $this->row['nominal_height'],
                'actual_height' => $this->row['actual_height'],
                'pack_size_1' => $this->row['pack_size_1'],
                'pack_size_2' => $this->row['pack_size_2'],
                'pack_size_3' => $this->row['pack_size_3'],
                'kg_per_m' => $this->row['kg_per_m'],
                'business_id' => null,
                'deprecated' => false,
            ]);
        }
    }

    private function findDatabaseRowToMatchSpreadsheetRow(array $row, object $allCurrentMasterProductRecords): ?int
    {
        $record = $allCurrentMasterProductRecords
            ->where('description', $row['description'])
            ->where('product_category', $row['product_category'])
            ->where('material', $row['material'])
            ->where('grade', $row['grade'])
            ->where('surface', $row['surface'])
            ->where('nesting_algo', $row['nesting_algo'])
            ->where('certificates', $row['certificates'])
            ->where('nominal_units', $row['nominal_units'])
            ->where('nominal_length', $row['nominal_length'])
            ->where('actual_length', $row['actual_length'])
            ->where('nominal_width', $row['nominal_width'])
            ->where('actual_width', $row['actual_width'])
            ->where('nominal_height', $row['nominal_height'])
            ->where('actual_height', $row['actual_height'])
            ->where('kg_per_m', $row['kg_per_m'])
            ->first();

        return $record ? $record->id : null;
    }
}
