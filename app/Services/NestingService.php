<?php

namespace App\Services;

use App\Enums\MaterialEnums;
use App\Enums\MeasurementUnitEnums;
use App\Enums\GradeEnums;
use App\Enums\ProductEnums;
use App\Enums\SurfaceEnums;
use App\Models\Piece;
use App\Models\Product;
use App\Models\RawMaterialQuote;
use App\Models\Template;
use App\Models\User;
use Exception;
use Illuminate\Support\Collection;

class NestingService
{
    public function nested(Collection $pieces): Collection
    {
        $materialSpecs = Piece::select('product', 'material', 'grade', 'surface', 'measurement_unit', 'size')
            ->whereIn("id",$pieces->pluck("id")->toArray())
            ->distinct()
            ->get();

        $result = [];
        foreach($materialSpecs as $materialSpec){
            $subPieces = $pieces
                ->where('product',$materialSpec->product)
                ->where('material',$materialSpec->material)
                ->where('grade',$materialSpec->grade)
                ->where('surface',$materialSpec->surface)
                ->where('measurement_unit',$materialSpec->measurement_unit)
                ->where('size',$materialSpec->size);

            $lengthsUnits = [];
            $cutLengths = [];
            foreach($subPieces as $subPiece){
                $lengthsUnits[] = [
                    "project" => $subPiece->project()->first(),
                    "length" => $subPiece->actual_length,
                    "measurement_unit" => $subPiece->measurement_unit,
                ];
                $cutLengths[] = $subPiece->actual_length;
            }

            $stockLengths = $this->getPurchasable($materialSpec);

            $appended = $materialSpec;
            $appended->lengthsUnits = $lengthsUnits;
            $appended->purchasable = $stockLengths;
            $appended->nested = $this->binPacking($cutLengths,$stockLengths);
            $result[] = $appended;
        }

        return collect($result);
    }

    function getPurchasable(Piece $materialSpec): array
    {
        return Product::query()
            ->where("product",$materialSpec->product)
            ->where("material",$materialSpec->material)
            ->where("grade",$materialSpec->grade)
            ->where("surface",$materialSpec->surface)
            ->where("measurement_unit",$materialSpec->measurement_unit)
            ->where("size",$materialSpec->size)
            ->pluck("length")
            ->toArray();
    }

    function binPacking($cutLengths, $stockLengths): array
    {
        // Sort cut lengths in descending order (FFD heuristic)
        rsort($cutLengths);

        // Initialize an array to represent the used stock bars
        $usedStockBars = [];
        $unfitCuts = []; // Cuts that cannot be placed in any stock bar

        // Process each cut length
        foreach ($cutLengths as $cut) {
            $placed = false;

            // Try to place the cut into an existing stock bar
            foreach ($usedStockBars as &$stock) {
                if ($stock['waste'] >= $cut) {
                    $stock['pieces'][] = $cut;
                    $stock['waste'] -= $cut;
                    $placed = true;
                    break;
                }
            }

            // If the cut doesn't fit into any existing stock bar, use a new one
            if (!$placed) {
                $newStockPlaced = false;
                foreach ($stockLengths as $stockLength) {
                    if ($stockLength >= $cut) {
                        $usedStockBars[] = [
                            'stock length' => $stockLength,
                            'waste' => $stockLength - $cut,
                            'pieces' => [$cut],
                        ];
                        $newStockPlaced = true;
                        break;
                    }
                }

                // If no new stock bar can accommodate the cut, add it to unfit cuts
                if (!$newStockPlaced) {
                    $unfitCuts[] = $cut;
                }
            }
        }

        return [
            'usedStockBars' => $usedStockBars,
            'unfitCuts' => $unfitCuts,
        ];
    }
}

