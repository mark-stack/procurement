<?php

namespace App\Services;

use App\Enums\MeasurementUnitEnums;
use App\Enums\NestingEnums;
use App\Enums\ProductEnums;
use App\Models\Piece;
use App\Models\Product;
use Illuminate\Support\Collection;
use stdClass;

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

    public function isPurchasableSize($rawMaterialQuote): bool
    {
        /**
         * Find the purchasable qty
         */

        $result = false;

        $measurementEnum = null;
        foreach(MeasurementUnitEnums::cases() as $enum){
            if($enum->value === $rawMaterialQuote["nominal_units"]){
                $measurementEnum = $enum;
            }
        }

        $productEnum = null;
        foreach(ProductEnums::cases() as $enum){
            if($enum->value === $rawMaterialQuote["product_category"]){
                $productEnum = $enum;
            }
        }

        /**
         * Material spec
         * 'product', 'material', 'grade', 'surface', 'nominal_units', 'size'
         */
        $materialSpec = new stdClass();
        $materialSpec->product = $rawMaterialQuote->product_category;
        $materialSpec->material = $rawMaterialQuote->material;
        $materialSpec->grade = 999; //todo
        $materialSpec->surface = 999; //todo
        $materialSpec->nominal_units = $rawMaterialQuote->nominal_units;
        $materialSpec->size = 999; //todo

        /**
         * Length to compare to stock sizes
         */
        $lengthToCompare = (float) $rawMaterialQuote->length_required;



        $purchasableLengths = $this->getPurchasableLengths($materialSpec);
        dd([
            "rawMaterialQuote" => $rawMaterialQuote,
            "measurementEnum" => $measurementEnum,
            "productEnum" => $productEnum,
            "purchasableLengths" => $purchasableLengths,
            "lengthToCompare" => $lengthToCompare,
        ]);


        $priceBookProducts = $this->findByAttributes(
            auth()->user(),
            $productEnum,
            $rawMaterialQuote["material"],
            null, //$grades,
            null, //$surface,
            $measurementEnum,
            null, //$size,
            null //$length,
        );

        if($priceBookProducts->count() > 0){
            $lengths = $priceBookProducts->pluck('length')->toArray();
            $normalisedToMeters = $this->normaliseArrayOfLengthsToMeters($lengths,$rawMaterialQuote["nominal_units"]);
            $providedLengthInMeters = (float) $rawMaterialQuote["length_required"];
            if(in_array($providedLengthInMeters,$normalisedToMeters)){
                $result = true;
            }
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

        $rawItems = Product::select("nominal_units")
            ->distinct()
            ->get()
            ->toArray();

        foreach($rawItems as $rawItem){
            $result[] = $rawItem["nominal_units"];
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

    public function getNestingLabelsFromProduct(string $product): array
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
                if($gradeLabel !== ""){
                    $nestingLabels = $this->allNestingAlgorithmLabels();
                    foreach($nestingLabels as $nestingLabel){
                        $resultArray["Other"][$materialLabel][$gradeLabel][] = $nestingLabel;
                        $resultArray["Other"][$materialLabel][$gradeLabel][] = "NONE";
                    }
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
                    if($gradeLabel !== ""){
                        $nestingLabels = $this->getNestingLabelsFromProduct($productLabel);
                        foreach($nestingLabels as $nestingLabel){
                            $resultArray[$productLabel][$materialLabel][$gradeLabel][] = $nestingLabel;
                        }
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
        $supplierGroups = config('supplier_groups');

        $resultAssigned = [];
        $resultUnassigned = [];
        foreach($piecesNested as $algoGroup){
            foreach($algoGroup as $piece){
                //Check if product is in batch group
                $product = $piece["product"];
                $productIsAssignedToBatch = false;
                foreach($supplierGroups as $batchLabel => $products){
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
        $materialSpecs = null;

        //METERAGE
        if($nestingAlgoLabel === NestingEnums::METERAGE->value){
            //$sizeInclude = ["nominal_height"];
            $materialSpecs = Piece::select('product', 'material', 'grade', 'surface', 'nominal_units', "nominal_height")
                ->whereIn("id",$allPieces->pluck("id")->toArray())
                ->distinct()
                ->get();

            foreach($materialSpecs as $materialSpec){
                $pieces = $allPieces
                    ->where('product',$materialSpec->product)
                    ->where('material',$materialSpec->material)
                    ->where('grade',$materialSpec->grade)
                    ->where('surface',$materialSpec->surface)
                    ->where('nominal_units',$materialSpec->nominal_units)
                    ->where('size',$materialSpec->size)
                    ->sortBy("size");

                $appended = $materialSpec;
                $appended->algo = $nestingAlgoLabel;

                $piecesArray = [];
                $cutLengths = [];
                foreach($pieces as $piece){
                    $piecesArray[] = [
                        "project" => $piece->project()->first(),
                        "length" => $piece->actual_length,
                        "nominal_units" => $piece->nominal_units,
                        "quantity" => $piece->actual_qty,
                    ];
                    for ($i = 0; $i < (int) $piece->actual_qty; $i++) {
                        $cutLengths[] = [
                            "project" => $piece->project()->first()->id,
                            "length" => $piece->actual_length,
                        ];
                    }
                }

                $purchasableLengths = $this->getPurchasableLengths($materialSpec);

                $appended->pieces = $piecesArray;
                $appended->purchasable = $purchasableLengths;
                $appended->nested = $this->meterageAlgorithm($cutLengths,$purchasableLengths);

                $result[] = $appended;
            }
        }
        //AREA
        if($nestingAlgoLabel === NestingEnums::AREA->value){
            //$sizeInclude = ["nominal_height"];
            $materialSpecs = Piece::select('product', 'material', 'grade', 'surface', 'nominal_units', "nominal_height")
                ->whereIn("id",$allPieces->pluck("id")->toArray())
                ->distinct()
                ->get();

            foreach($materialSpecs as $materialSpec){
                $pieces = $allPieces
                    ->where('product',$materialSpec->product)
                    ->where('material',$materialSpec->material)
                    ->where('grade',$materialSpec->grade)
                    ->where('surface',$materialSpec->surface)
                    ->where('nominal_units',$materialSpec->nominal_units)
                    ->where('size',$materialSpec->size)
                    ->sortBy("size");

                $appended = $materialSpec;
                $appended->algo = $nestingAlgoLabel;

                $stockLengths = [];
                $piecesArray = [];

                //todo loop

                $appended->pieces = $piecesArray;
                $appended->purchasable = $stockLengths;
                $appended->nested = []; //todo

                $result[] = $appended;
            }
        }
        //BUNDLE
        if($nestingAlgoLabel === NestingEnums::BUNDLE->value){
            //$sizeInclude = ["nominal_length","nominal_width"];
            $materialSpecs = Piece::select('product', 'material', 'grade', 'surface', 'nominal_units', "nominal_length","nominal_width")
                ->whereIn("id",$allPieces->pluck("id")->toArray())
                ->distinct()
                ->get();

            foreach($materialSpecs as $materialSpec){
                $pieces = $allPieces
                    ->where('product',$materialSpec->product)
                    ->where('material',$materialSpec->material)
                    ->where('grade',$materialSpec->grade)
                    ->where('surface',$materialSpec->surface)
                    ->where('nominal_units',$materialSpec->nominal_units)
                    ->where('size',$materialSpec->size)
                    ->sortBy("size");

                $appended = $materialSpec;
                $appended->algo = $nestingAlgoLabel;

                $piecesArray = [];
                $boxSizes = $this->getPurchasableLengths($materialSpec); //todo: "lengths" is substitute for qty?
                $totalQty = 0;
                foreach($pieces as $piece){
                    $piecesArray[] = [
                        "project" => $piece->project()->first(),
                        "length" => null,
                        "nominal_units" => $piece->nominal_units,
                        "quantity" => $piece->actual_qty,
                    ];
                    $totalQty = $totalQty + $piece->actual_qty;
                }

                $appended->pieces = $piecesArray;
                $appended->purchasable = $boxSizes;
                $appended->nested = $this->bundleAlgorithm($totalQty,$boxSizes);

                $result[] = $appended;
            }
        }

        ////////////////////////
//        $result = [];
//
//        $materialSpecs = Piece::select('product', 'material', 'grade', 'surface', 'nominal_units', 'size')
//            ->whereIn("id",$allPieces->pluck("id")->toArray())
//            ->distinct()
//            ->get();
//
//        foreach($materialSpecs as $materialSpec){
//            $pieces = $allPieces
//                ->where('product',$materialSpec->product)
//                ->where('material',$materialSpec->material)
//                ->where('grade',$materialSpec->grade)
//                ->where('surface',$materialSpec->surface)
//                ->where('nominal_units',$materialSpec->nominal_units)
//                ->where('size',$materialSpec->size)
//                ->sortBy("size");
//
//            $appended = $materialSpec;
//            $appended->algo = $nestingAlgoLabel;
//
//            //METERAGE
//            if($nestingAlgoLabel === NestingEnums::METERAGE->value){
//                $piecesArray = [];
//                $cutLengths = [];
//                foreach($pieces as $piece){
//                    $piecesArray[] = [
//                        "project" => $piece->project()->first(),
//                        "length" => $piece->actual_length,
//                        "nominal_units" => $piece->nominal_units,
//                        "quantity" => $piece->actual_qty,
//                    ];
//                    for ($i = 0; $i < (int) $piece->actual_qty; $i++) {
//                        $cutLengths[] = [
//                            "project" => $piece->project()->first()->id,
//                            "length" => $piece->actual_length,
//                        ];
//                    }
//                }
//
//                $purchasableLengths = $this->getPurchasableLengths($materialSpec);
//
//                $appended->pieces = $piecesArray;
//                $appended->purchasable = $purchasableLengths;
//                $appended->nested = $this->meterageAlgorithm($cutLengths,$purchasableLengths);
//            }
//            //AREA
//            if($nestingAlgoLabel === NestingEnums::AREA->value){
//                $stockLengths = [];
//                $piecesArray = [];
//
//                //todo loop
//
//                $appended->pieces = $piecesArray;
//                $appended->purchasable = $stockLengths;
//                $appended->nested = []; //todo
//            }
//            //BUNDLE
//            if($nestingAlgoLabel === NestingEnums::BUNDLE->value){
//                $piecesArray = [];
//                $boxSizes = $this->getPurchasableLengths($materialSpec); //todo: "lengths" is substitute for qty?
//                $totalQty = 0;
//                foreach($pieces as $piece){
//                    $piecesArray[] = [
//                        "project" => $piece->project()->first(),
//                        "length" => null,
//                        "nominal_units" => $piece->nominal_units,
//                        "quantity" => $piece->actual_qty,
//                    ];
//                    $totalQty = $totalQty + $piece->actual_qty;
//                }
//
//                $appended->pieces = $piecesArray;
//                $appended->purchasable = $boxSizes;
//                $appended->nested = $this->bundleAlgorithm($totalQty,$boxSizes);
//            }
//
//            $result[] = $appended;
//        }

        return collect($result);
    }

    function getPurchasableVariations(Object $materialSpec): array
    {
        /**
         * METERAGE = nominal_length
         * AREA = nominal_length & nominal_width
         * BUNDLE = pack size
         */
        return Product::query()
            ->where("product",$materialSpec->product)
            ->where("material",$materialSpec->material)
            ->where("grade",$materialSpec->grade)
            ->where("surface",$materialSpec->surface)
            ->where("nominal_units",$materialSpec->nominal_units)
            ->where("size",$materialSpec->size)
            ->pluck("nominal_length") //todo
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

