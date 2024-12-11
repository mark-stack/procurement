<?php

namespace App\Services;

use App\Enums\NestingEnums;
use App\Enums\ProductEnums;
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

    public function batchGroups(array $piecesNested): array
    {
        $batches = [
            "Metal Merchant" => [
                ProductEnums::UB->value,
                ProductEnums::UC->value,
                ProductEnums::SHS->value,
                ProductEnums::PFC->value,
                ProductEnums::PLATE->value,
            ],
            "Timber Merchant" => [
                ProductEnums::LVL->value,
            ],
            "Fasteners" => [
                ProductEnums::BOLT->value,
            ],
        ];

        $resultAssigned = [];
        $resultUnassigned = [];
        foreach($piecesNested as $algoGroup){
            foreach($algoGroup as $piece){
                //Check if product is in batch group
                $product = $piece["product"];
                $productIsAssignedToBatch = false;
                foreach($batches as $batchLabel => $products){
                    if(in_array($product,$products)){
                        $resultAssigned[$batchLabel][] = $piece;
                        $productIsAssignedToBatch = true;
                    }
                }

                //if not assigned
                if(!$productIsAssignedToBatch){
                    $resultUnassigned[] = $piece;
                }
            }
        }

        /**
         * Place in order of size
         */
        $orderedResultAssigned = [];
        foreach($resultAssigned as $index => $pieces){
            $orderedResultAssigned[$index] = array_values(collect($pieces)->sortBy("size")->toArray());
        }

        return [
            "assigned" => $orderedResultAssigned,
            "unassigned" => $resultUnassigned,
        ];
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
                ->where('size',$materialSpec->size)
                ->sortBy("size");

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
                    }
                }

                $purchasableLengths = $this->getPurchasable($materialSpec);

                $appended->pieces = $piecesArray;
                $appended->purchasable = $purchasableLengths;
                $appended->nested = $this->meterageAlgorithm($cutLengths,$purchasableLengths);
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
                $boxSizes = $this->getPurchasable($materialSpec);
                $totalQty = 0;
                foreach($pieces as $piece){
                    $piecesArray[] = [
                        "project" => $piece->project()->first(),
                        "length" => null,
                        "measurement_unit" => $piece->measurement_unit,
                        "quantity" => $piece->actual_qty,
                    ];
                    $totalQty = $totalQty + $piece->actual_qty;
                }

                $appended->pieces = $piecesArray;
                $appended->purchasable = $boxSizes;
                $appended->nested = $this->bundleAlgorithm($totalQty,$boxSizes);
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

    function meterageAlgorithm(array $cutLengths, array $stockLengths): array
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

                        $usedStockBars[] = [
                            'stock length' => $stockLength,
                            'waste' => $stockLength - $cutLength,
                            'pieces' => [array($cutLength,$cut["project"])],
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
        $orderList = $this->orderList($usedStockBars);
        $usedStockBars = $this->consolidateStockNestingResults($usedStockBars);

        return [
            'usedStockBars' => $usedStockBars,
            'unfitCuts' => $unfitCuts,
            "orderList" => $orderList,
        ];
    }

    public function bundleAlgorithm(int $totalQty, array $boxSizes): array
    {
        $originalQty = $totalQty;

        // Sort the box sizes in descending order
        rsort($boxSizes);

        $boxCounts = []; // To store the number of each box size used
        foreach ($boxSizes as $boxSize) {
            // Calculate how many of this box size we need
            $boxCounts[$boxSize] = intdiv($totalQty, $boxSize);
            // Reduce the total number of bolts left
            $totalQty %= $boxSize;
        }

        // If there are leftover bolts, we need one extra smallest box
        if ($totalQty > 0) {
            $boxCounts[$boxSizes[count($boxSizes) - 1]] += 1;
        }

        /**
         * totals
         */
        $totalBought = 0;
        foreach ($boxCounts as $key => $value) {
            $totalBought += $key * $value;
        }

        return [
            "totalBought" => $totalBought,
            "efficiency" => ($originalQty/$totalBought*100),
            "boxes" => $boxCounts,
        ];
    }

//    function meterageAlgorithm(array $cutLengths, array $stockLengths): array
//    {
//        // Sort cut lengths in descending order (FFD heuristic)
//        rsort($cutLengths);
//
//        // Initialize an array to represent the used stock bars
//        $usedStockBars = [];
//        $unfitCuts = []; // Cuts that cannot be placed in any stock bar
//
//        // Process each cut length
//        foreach ($cutLengths as $cut) {
//            $placed = false;
//
//            // Try to place the cut into an existing stock bar
//            foreach ($usedStockBars as &$stock) {
//                if ($stock['waste'] >= $cut) {
//                    $stock['pieces'][] = $cut;
//                    $stock['waste'] -= $cut;
//                    $placed = true;
//                    break;
//                }
//            }
//
//            // If the cut doesn't fit into any existing stock bar, use a new one
//            if (!$placed) {
//                $newStockPlaced = false;
//                foreach ($stockLengths as $stockLength) {
//                    if ($stockLength >= $cut) {
//                        $usedStockBars[] = [
//                            'stock length' => $stockLength,
//                            'waste' => $stockLength - $cut,
//                            'pieces' => [$cut],
//                        ];
//                        $newStockPlaced = true;
//                        break;
//                    }
//                }
//
//                // If no new stock bar can accommodate the cut, add it to unfit cuts
//                if (!$newStockPlaced) {
//                    $unfitCuts[] = $cut;
//                }
//            }
//        }
//
//        /**
//         * Consolidate sued stock bars that are the same (same length and cuts array)
//         */
//        $usedStockBars = $this->consolidateStockNestingResults($usedStockBars);
//
//        return [
//            'usedStockBars' => $usedStockBars,
//            'unfitCuts' => $unfitCuts,
//        ];
//    }

    public function consolidateStockNestingResults(array $usedStockBars): array
    {
        /**
         * This list is unique stock length cuts.
         * If 2 items area identical except the project refs are different, they'll be treated as different.
         */
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

    public function orderList(array $usedStockBars): array
    {
        /**
         * This list is unique stock length.
         * If 2 items area identical except the project refs are different, they'll be treated as the same.
         */
        //Remove the project ID so they consolidate disregarding project refs
        $newResult = [];
        foreach($usedStockBars as $bar){
            unset($bar["waste"]);
            unset($bar["pieces"][0][1]);
            $newResult[] = $bar["stock length"];
        }

        //dd($newResult);
        // Step 1: Serialize each array
        $serialized = array_map('serialize', $newResult);

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

