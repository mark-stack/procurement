<?php

namespace App\Services;

use App\Enums\NestingEnums;
use App\Models\Piece;
use App\Models\Product;
use Illuminate\Support\Collection;

class NestingService
{
    public function allNestingAlgorithmLabels(): array
    {
        $result = [];

        $rawItems = Product::select("nesting_algo")
            ->distinct()
            ->get()
            ->toArray();

        foreach($rawItems as $rawItem){
            $result[] = $rawItem["nesting_algo"];
        }

        return $result;
    }

    public function allProductLabels(): array
    {
        $result = [];

        $rawItems = Product::select("product")
            ->distinct()
            ->get()
            ->toArray();

        foreach($rawItems as $rawItem){
            $result[] = $rawItem["product"];
        }

        return $result;
    }

    public function allMaterialLabels(): array
    {
        $result = [];

        $rawItems = Product::select("material")
            ->distinct()
            ->get()
            ->toArray();

        foreach($rawItems as $rawItem){
            $result[] = $rawItem["material"];
        }

        return $result;
    }

    public function getCertificateProductLabels(): array
    {
        $result = [];

        $rawItems = Product::select("product")
            ->distinct()
            ->where("certificates",true)
            ->get()
            ->toArray();

        foreach($rawItems as $rawItem){
            $result[] = $rawItem["product"];
        }

        return $result;
    }

    public function allGradeLabels(): array
    {
        $result = [];

        $rawItems = Product::select("grade")
            ->distinct()
            ->get()
            ->toArray();

        foreach($rawItems as $rawItem){
            $result[] = $rawItem["grade"];
        }

        return $result;
    }

    public function allMeasurementUnitLabels(): array
    {
        $result = [];

        $rawItems = Product::select("measurement_unit")
            ->distinct()
            ->get()
            ->toArray();

        foreach($rawItems as $rawItem){
            $result[] = $rawItem["measurement_unit"];
        }

        return $result;
    }

    public function getMaterialLabelsFromProduct(string $product): array
    {
        $result = [];

        $rawItems = Product::select("material")
            ->distinct()
            ->where("product",$product)
            ->get()
            ->toArray();

        foreach($rawItems as $rawItem){
            $result[] = $rawItem["material"];
        }

        return $result;
    }

    public function getGradeLabelsFromMaterial(?string $product,string $material): array
    {
        $result = [];

        //Product provides
        if($product){
            $rawItems = Product::select("grade")
                ->distinct()
                ->where("product",$product)
                ->where("material",$material)
                ->get()
                ->toArray();

            foreach($rawItems as $rawItem){
                $result[] = $rawItem["grade"];
            }
        }
        else{
            $rawItems = Product::select("grade")
                ->distinct()
                ->where("material",$material)
                ->get()
                ->toArray();

            foreach($rawItems as $rawItem){
                $result[] = $rawItem["grade"];
            }
        }

        return $result;
    }

    public function getNestingLabelsFromProduct(string $product)
    {
        $result = [];

        $rawItems = Product::select("nesting_algo")
            ->distinct()
            ->where("product",$product)
            ->get()
            ->toArray();

        foreach($rawItems as $rawItem){
            $result[] = $rawItem["nesting_algo"];
        }

        return $result;
    }

    public function buildDependencyArray(): array
    {
        /**
         * Dependency Array:
         *   - Product (single)
         *     - Materials (multiple)
         *       - Grades (multiple)
         *          - Nesting Algo (single)
         */

        $resultArray = [];

        //All Materials
        $materialLabels = $this->allMaterialLabels();
        foreach($materialLabels as $materialLabel){
            //Grades
            $gradeLabels = $this->getGradeLabelsFromMaterial(null,$materialLabel);
            foreach($gradeLabels as $gradeLabel){
                //Nesting
                $nestingLabels = $this->allNestingAlgorithmLabels();
                foreach($nestingLabels as $nestingLabel){
                    $resultArray["Other"][$materialLabel][$gradeLabel] = $nestingLabel;
                }
            }
        }

        //Products
        $allProductLabels = $this->allProductLabels();
        foreach($allProductLabels as $productLabel){
            //Materials
            $materialLabels = $this->getMaterialLabelsFromProduct($productLabel);
            foreach($materialLabels as $materialLabel){
                //Grades
                $gradeLabels = $this->getGradeLabelsFromMaterial($productLabel,$materialLabel);
                foreach($gradeLabels as $gradeLabel){
                    //Nesting
                    $nestingLabels = $this->getNestingLabelsFromProduct($productLabel);
                    foreach($nestingLabels as $nestingLabel){
                        $resultArray[$productLabel][$materialLabel][$gradeLabel] = $nestingLabel;
                    }
                }
            }
        }

        return $resultArray;
    }

    public function piecesClassifiedByNestingAlgorithm(Collection $pieces): Collection
    {
        /**
         * Different algorithms:
         * No minimum quantity: NONE
         * Pack/box: BUNDLE
         * Meterage: METERAGE
         * 2D area: AREA
         */

        return $pieces->groupBy("nesting_algo");
    }

    public function nesting(string $nestingAlgoLabel, Collection $allPieces): Collection
    {
        $result = [];

        $materialSpecs = Piece::select('product', 'material', 'grade', 'surface', 'measurement_unit', 'size')
            ->whereIn("id",$allPieces->pluck("id")->toArray())
            ->distinct()
            ->get();

        foreach($materialSpecs as $materialSpec){
            $pieces = $allPieces
                ->where('product',$materialSpec->product)
                ->where('material',$materialSpec->material)
                ->where('grade',$materialSpec->grade)
                ->where('surface',$materialSpec->surface)
                ->where('measurement_unit',$materialSpec->measurement_unit)
                ->where('size',$materialSpec->size);

            $appended = $materialSpec;
            $appended->algo = $nestingAlgoLabel;

            //METERAGE
            if($nestingAlgoLabel === NestingEnums::METERAGE->value){
                $piecesArray = [];
                $cutLengths = [];
                foreach($pieces as $piece){
                    $piecesArray[] = [
                        "project" => $piece->project()->first(),
                        "length" => $piece->actual_length,
                        "measurement_unit" => $piece->measurement_unit,
                        "quantity" => $piece->actual_qty,
                    ];
                    for ($i = 0; $i < (int) $piece->actual_qty; $i++) {
                        $cutLengths[] = [
                            "project" => $piece->project()->first()->id,
                            "length" => $piece->actual_length,
                        ];
                        //$cutLengths[] = $piece->actual_length;
                    }
                }

                $purchasableLengths = $this->getPurchasable($materialSpec);

                $appended->pieces = $piecesArray;
                $appended->purchasable = $purchasableLengths;
                $appended->nested = $this->meterageAlgorithm2($cutLengths,$purchasableLengths);
            }
            //AREA
            if($nestingAlgoLabel === NestingEnums::AREA->value){
                $stockLengths = [];
                $piecesArray = [];

                //todo loop

                $appended->pieces = $piecesArray;
                $appended->purchasable = $stockLengths;
                $appended->nested = []; //todo
            }
            //BUNDLE
            if($nestingAlgoLabel === NestingEnums::BUNDLE->value){
                $piecesArray = [];
                $purchasablePackBundleQuantities = $this->getPurchasable($materialSpec);

                foreach($pieces as $piece){
                    $piecesArray[] = [
                        "project" => $piece->project()->first(),
                        "length" => null,
                        "measurement_unit" => $piece->measurement_unit,
                        "quantity" => $piece->actual_qty,
                    ];
                    for ($i = 0; $i < (int) $piece->actual_qty; $i++) {
                        $cutLengths[] = $piece->actual_length;
                    }
                }

                $appended->pieces = $piecesArray;
                $appended->purchasable = $purchasablePackBundleQuantities;
                $appended->nested = []; //todo
            }

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

    function meterageAlgorithm2(array $cutLengths, array $stockLengths): array
    {
        // Sort cut lengths in descending order (FFD heuristic)
        //rsort($cutLengths);

        usort($cutLengths, function ($a, $b) {
            return $b['length'] <=> $a['length']; //descending order
        });

        // Initialize an array to represent the used stock bars
        $usedStockBars = [];
        $unfitCuts = []; // Cuts that cannot be placed in any stock bar

        // Process each cut length
        foreach ($cutLengths as $cut) {
            $placed = false;

            // Try to place the cut into an existing stock bar
            foreach ($usedStockBars as &$stock) {
                $cutLength = (int) $cut["length"];

                if ($stock['waste'] >= $cutLength) {
                    $stock['pieces'][] = array($cutLength,$cut["project"]);
                    $stock['waste'] -= $cutLength;
                    $placed = true;
                    break;
                }
            }

            // If the cut doesn't fit into any existing stock bar, use a new one
            if (!$placed) {
                $newStockPlaced = false;
                foreach ($stockLengths as $stockLength) {
                    if ($stockLength >= $cut["length"]) {
                        $cutLength = (int) $cut["length"];

                        $x = [$cutLength,$cut["project"]];

                        $usedStockBars[] = [
                            'stock length' => $stockLength,
                            'waste' => $stockLength - $cutLength,
                            'pieces' => [array($cutLength,$cut["project"])],
//                            [
//                                "project" => $cut["project"],
//                                "length" => $cutLength,
//                            ],
                        ];
                        $newStockPlaced = true;
                        break;
                    }
                }

                // If no new stock bar can accommodate the cut, add it to unfit cuts
                if (!$newStockPlaced) {
                    $unfitCuts[] = [
                        "project" => $cut["project"],
                        "length" => $cut["length"],
                    ];
                }
            }
        }

        /**
         * Consolidate sued stock bars that are the same (same length and cuts array)
         */
        //dd($usedStockBars);
        $usedStockBars = $this->consolidateStockNestingResults($usedStockBars);

        return [
            'usedStockBars' => $usedStockBars,
            'unfitCuts' => $unfitCuts,
        ];
    }

    function meterageAlgorithm(array $cutLengths, array $stockLengths): array
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

        /**
         * Consolidate sued stock bars that are the same (same length and cuts array)
         */
        $usedStockBars = $this->consolidateStockNestingResults($usedStockBars);

        return [
            'usedStockBars' => $usedStockBars,
            'unfitCuts' => $unfitCuts,
        ];
    }

    public function consolidateStockNestingResults(array $usedStockBars): array
    {
        //"stock length"
        //"pieces"

        // Step 1: Serialize each array
        $serialized = array_map('serialize', $usedStockBars);

        // Step 2: Count occurrences
        $counted = array_count_values($serialized);

        // Step 3: Unserialize keys to get original arrays
        $result = [];
        foreach ($counted as $key => $count) {
            $result[] = [
                "count" => $count,
                "result" => unserialize($key),
            ];
        }

        return $result;
    }
}

