<?php

namespace App\Formatters;

use App\Enums\GradeEnums;
use App\Enums\MaterialEnums;
use App\Enums\NestingEnums;
use App\Enums\ProductEnums;
use App\Http\Resources\ProjectResource;
use App\Models\Batch;
use App\Models\Business;
use App\Models\Piece;
use App\Models\Product;
use App\Services\ProductService;
use Illuminate\Support\Collection;

class NestingFormatter
{
    /**
     * Arrays
     */
    public function getCertificateProductLabels(): array
    {
        $result = [];

        $rawItems = Product::select('product_category')
            ->active()
            ->distinct()
            ->where('certificates', true)
            ->get()
            ->toArray();

        foreach ($rawItems as $rawItem) {
            $result[] = $rawItem['product_category'];
        }

        return $result;
    }

    public function allGradeLabels(): array
    {
        $result = [];

        $rawItems = Product::select('grade')
            ->active()
            ->distinct()
            ->get()
            ->toArray();

        foreach ($rawItems as $rawItem) {
            $result[] = $rawItem['grade'];
        }

        return $result;
    }

    public function allMeasurementUnitLabels(): array
    {
        $result = [];

        $rawItems = Product::select('nominal_units')
            ->active()
            ->distinct()
            ->get()
            ->toArray();

        foreach ($rawItems as $rawItem) {
            $result[] = $rawItem['nominal_units'];
        }

        return $result;
    }

    public function getNestingLabelsFromProductCategory(string $productCategory): array
    {
        $result = [];

        $rawItems = Product::select('nesting_algo')
            ->active()
            ->distinct()
            ->where('product_category', $productCategory)
            ->get()
            ->toArray();

        foreach ($rawItems as $rawItem) {
            $result[] = $rawItem['nesting_algo'];
        }

        return $result;
    }

    private function buildDependencyComponent(object $material, array $gradesGroups, array $nestingAlgos): array
    {
        $fastenerGrades = [
            GradeEnums::GR_4_6,
            GradeEnums::GR_5_8,
            GradeEnums::GR_8_8,
            GradeEnums::GR_10_9,
            GradeEnums::GR_12_9,
        ];

        $plainCarbonGrades = [
            GradeEnums::GR250,
            GradeEnums::GR300,
            GradeEnums::GR350,
        ];

        $stainlessGrades = [
            GradeEnums::SS304,
            GradeEnums::SS316,
        ];

        $timberGrades = [
            GradeEnums::E13,
        ];

        $plasticGrades = [
            GradeEnums::HDPE,
        ];

        $alloyGrades = [
            GradeEnums::GR_6060,
            GradeEnums::GR_6061,
        ];

        $hardoxGrades = [
            GradeEnums::HARDOX_400,
            GradeEnums::HARDOX_450,
            GradeEnums::HARDOX_500,
            GradeEnums::HARDOX_500_TUF,
            GradeEnums::HARDOX_550,
            GradeEnums::HARDOX_600,
            GradeEnums::HARDOX_HI_TUF,
            GradeEnums::HARDOX_EXTREME,
            GradeEnums::HARDOX_HI_TEMP,
        ];

        $gradesArray = [];

        $algoValues = [];
        foreach ($nestingAlgos as $algo) {
            $algoValues[] = $algo->value;
        }

        foreach ($gradesGroups as $group) {
            //Fasteners
            if ($group === 'FASTENERS') {
                foreach ($fastenerGrades as $grade) {
                    $gradesArray[$grade->value] = $algoValues;
                }
            }

            //Plain carbon
            if ($group === 'PLAIN_CARBON') {
                foreach ($plainCarbonGrades as $grade) {
                    $gradesArray[$grade->value] = $algoValues;
                }
            }

            //Stainless
            if ($group === 'STAINLESS') {
                foreach ($stainlessGrades as $grade) {
                    $gradesArray[$grade->value] = $algoValues;
                }
            }

            //Timber
            if ($group === 'TIMBER') {
                foreach ($timberGrades as $grade) {
                    $gradesArray[$grade->value] = $algoValues;
                }
            }

            //Plastic
            if ($group === 'PLASTIC') {
                foreach ($plasticGrades as $grade) {
                    $gradesArray[$grade->value] = $algoValues;
                }
            }

            //Alloys & aluminium
            if ($group === 'ALLOY') {
                foreach ($alloyGrades as $grade) {
                    $gradesArray[$grade->value] = $algoValues;
                }
            }

            //Hardox
            if ($group === 'HARDOX') {
                foreach ($hardoxGrades as $grade) {
                    $gradesArray[$grade->value] = $algoValues;
                }
            }
        }
        $gradesArray['Other'] = $algoValues;

        return [
            $material->value => $gradesArray,
        ];
    }

    private function buildDependencyOtherComponent(): array
    {
        $algoValues = [
            NestingEnums::METERAGE->value,
            NestingEnums::BUNDLE->value,
            NestingEnums::AREA->value,
        ];

        return [
            'Other' => [
                GradeEnums::GR250->value => $algoValues,
                GradeEnums::GR300->value => $algoValues,
                GradeEnums::GR350->value => $algoValues,
                GradeEnums::GR_12_9->value => $algoValues,
                GradeEnums::GR_10_9->value => $algoValues,
                GradeEnums::GR_8_8->value => $algoValues,
                GradeEnums::GR_5_8->value => $algoValues,
                GradeEnums::GR_4_6->value => $algoValues,
                GradeEnums::E13->value => $algoValues,
                GradeEnums::HDPE->value => $algoValues,
                GradeEnums::GR_6060->value => $algoValues,
                GradeEnums::GR_6061->value => $algoValues,
                GradeEnums::HARDOX_400->value => $algoValues,
                GradeEnums::HARDOX_450->value => $algoValues,
                GradeEnums::HARDOX_500->value => $algoValues,
                GradeEnums::HARDOX_500_TUF->value => $algoValues,
                GradeEnums::HARDOX_550->value => $algoValues,
                GradeEnums::HARDOX_600->value => $algoValues,
                GradeEnums::HARDOX_HI_TUF->value => $algoValues,
                GradeEnums::HARDOX_EXTREME->value => $algoValues,
                GradeEnums::HARDOX_HI_TEMP->value => $algoValues,
            ],
        ];
    }

    public function buildDependencyArray2(): array
    {
        /**
         * Dependency Array:
         *   - Product category (single)
         *     - Materials (multiple)
         *       - Grades (multiple)
         *          - Nesting Algo (single)
         */
        $all = array_merge(
            $this->buildDependencyComponent(
                MaterialEnums::PLAIN_CARBON_STEEL,
                ['FASTENERS', 'PLAIN_CARBON'],
                [NestingEnums::METERAGE, NestingEnums::BUNDLE, NestingEnums::AREA]
            ),
            $this->buildDependencyComponent(
                MaterialEnums::STAINLESS_STEEL,
                ['FASTENERS', 'STAINLESS'],
                [NestingEnums::METERAGE, NestingEnums::BUNDLE, NestingEnums::AREA]
            ),
            $this->buildDependencyComponent(
                MaterialEnums::ALUMINIUM,
                ['ALLOY'],
                [NestingEnums::METERAGE, NestingEnums::BUNDLE, NestingEnums::AREA]
            ),
            $this->buildDependencyComponent(
                MaterialEnums::PLASTIC,
                ['PLASTIC'],
                [NestingEnums::METERAGE, NestingEnums::BUNDLE, NestingEnums::AREA]
            ),
            $this->buildDependencyComponent(
                MaterialEnums::TIMBER,
                ['TIMBER'],
                [NestingEnums::METERAGE, NestingEnums::BUNDLE, NestingEnums::AREA]
            ),
            $this->buildDependencyComponent(
                MaterialEnums::HARDOX,
                ['HARDOX'],
                [NestingEnums::METERAGE, NestingEnums::BUNDLE, NestingEnums::AREA]
            ),
            $this->buildDependencyOtherComponent(),
        );

        $sections = array_merge(
            $this->buildDependencyComponent(
                MaterialEnums::PLAIN_CARBON_STEEL,
                ['PLAIN_CARBON'],
                [NestingEnums::METERAGE]
            ),
            $this->buildDependencyComponent(
                MaterialEnums::STAINLESS_STEEL,
                ['STAINLESS'],
                [NestingEnums::METERAGE]
            ),
            $this->buildDependencyComponent(
                MaterialEnums::ALUMINIUM,
                ['ALLOY'],
                [NestingEnums::METERAGE]
            ),
            $this->buildDependencyOtherComponent(),
        );

        $plates = array_merge(
            $this->buildDependencyComponent(
                MaterialEnums::PLAIN_CARBON_STEEL,
                ['PLAIN_CARBON'],
                [NestingEnums::AREA]
            ),
            $this->buildDependencyComponent(
                MaterialEnums::STAINLESS_STEEL,
                ['STAINLESS'],
                [NestingEnums::AREA]
            ),
            $this->buildDependencyComponent(
                MaterialEnums::ALUMINIUM,
                ['ALLOY'],
                [NestingEnums::AREA]
            ),
            $this->buildDependencyOtherComponent(),
        );

        $fasteners = array_merge(
            $this->buildDependencyComponent(
                MaterialEnums::PLAIN_CARBON_STEEL,
                ['FASTENERS'],
                [NestingEnums::BUNDLE]
            ),
            $this->buildDependencyComponent(
                MaterialEnums::STAINLESS_STEEL,
                ['FASTENERS', 'STAINLESS'],
                [NestingEnums::BUNDLE]
            ),
            $this->buildDependencyComponent(
                MaterialEnums::ALUMINIUM,
                ['FASTENERS', 'ALLOY'],
                [NestingEnums::BUNDLE]
            ),
            $this->buildDependencyOtherComponent(),
        );

        $timber = array_merge(
            $this->buildDependencyComponent(
                MaterialEnums::TIMBER,
                ['TIMBER'],
                [NestingEnums::METERAGE]
            ),
            $this->buildDependencyOtherComponent(),
        );

        return [
            'Other' => $all,
            ProductEnums::PFC->value => $sections,          //Meterage
            ProductEnums::UB->value => $sections,           //Meterage
            ProductEnums::UC->value => $sections,           //Meterage
            ProductEnums::CHS->value => $sections,          //Meterage
            ProductEnums::LVL->value => $timber,            //Meterage
            ProductEnums::PLATE->value => $plates,          //Area
            ProductEnums::ANCHOR_STUD->value => $fasteners, //Bundle
            ProductEnums::ALLTHREAD->value => $fasteners,   //Bundle
            ProductEnums::HEX_BOLT->value => $fasteners,    //Bundle
            ProductEnums::NUT->value => $fasteners,         //Bundle
            ProductEnums::CSK_BOLT->value => $fasteners,    //Bundle
            ProductEnums::ROUND->value => $sections,        //Meterage
            ProductEnums::EA->value => $sections,           //Meterage
            ProductEnums::UA->value => $sections,           //Meterage
            ProductEnums::RHS->value => $sections,          //Meterage
        ];
    }

    public function piecesNested(Collection $pieces, array $lettersProjectArray): array
    {
        $groupedByAlgo = $pieces->groupBy('nesting_algo');

        $piecesNested = [];
        foreach ($groupedByAlgo as $nestingAlgoLabel => $pieces) {
            $piecesNested[] = $this->nesting($nestingAlgoLabel, $pieces, $lettersProjectArray);
        }

        return $piecesNested;
    }

    public function usageStats(array $piecesNested): array
    {
        /**
         * Sums of material totals, usage, and waste
         */
        $totalPurchasedMaterial = 0;
        $totalUsedMaterial = 0;
        $totalWaste = 0;

        foreach ($piecesNested as $items) {
            foreach ($items as $item) {
                $item = (array) $item;

                if (isset($item['nested']['totals'])) {
                    $totals = $item['nested']['totals'];
                    $totalPurchasedMaterial = $totalPurchasedMaterial + $totals['totalPurchasedMaterial'];
                    $totalUsedMaterial = $totalUsedMaterial + $totals['totalUsedMaterial'];
                    $totalWaste = $totalWaste + $totals['totalWaste'];
                }
            }
        }

        return [
            'totalPurchasedMaterial' => $totalPurchasedMaterial,
            'totalUsedMaterial' => $totalUsedMaterial,
            'totalWaste' => $totalWaste,
            'efficiency' => $totalPurchasedMaterial === 0
                ? 0
                : (round($totalUsedMaterial / $totalPurchasedMaterial * 100)),
        ];
    }

    public function getLetterProjectArray(Collection $piecesReadyForBatching): array
    {
        $projectIds = [];
        foreach ($piecesReadyForBatching as $piece) {
            $projectIds[] = $piece->project_id;
        }
        $projectIds = array_values(array_unique($projectIds));

        $lettersProjectArray = [];

        foreach ($projectIds as $index => $id) {
            $letter = match ($index) {
                0 => 'A',
                1 => 'B',
                2 => 'C',
                3 => 'D',
                4 => 'E',
                5 => 'F',
                6 => 'G',
                7 => 'H',
                8 => 'I',
                9 => 'J',
                10 => 'K',
                11 => 'L',
                12 => 'M',
                13 => 'N',
                default => 'O', //shouldn't get this far
            };

            $lettersProjectArray[$id] = $letter;
        }

        return $lettersProjectArray;
    }

    public function piecesGroupedBySupplierGroup(array $piecesNested, Business $business): array
    {
        /**
         * Group nested pieces by supplier group. e.g "steel merchant"
         */
        $supplierGroupsWithIncludedProducts = (new SupplierFormatter)->supplierGroups($business);

        $resultAssigned = [];
        $resultUnassigned = [];
        foreach ($piecesNested as $algoGroup) {
            foreach ($algoGroup as $pieceData) {
                //Check if the piece is in the supplier group
                $pieceData = (array) $pieceData;
                $productCategoryOfPiece = $pieceData['product_category'];
                $pieceIsAssignedToBatch = false;
                foreach ($supplierGroupsWithIncludedProducts as $supplierGroup => $includedProducts) {
                    if (in_array($productCategoryOfPiece, $includedProducts)) {
                        $resultAssigned[$supplierGroup][] = $pieceData;
                        $pieceIsAssignedToBatch = true;
                    }
                }

                //if not assigned
                if (! $pieceIsAssignedToBatch) {
                    $resultUnassigned[] = $pieceData;
                }
            }
        }

        /**
         * Place in order of size
         */
        $orderedResultAssigned = [];
        foreach ($resultAssigned as $index => $pieces) {
            $orderedResultAssigned[$index] = array_values(collect($pieces)->sortBy('size')->toArray());
        }

        return [
            'assigned' => $orderedResultAssigned,
            'unassigned' => $resultUnassigned,
        ];
    }

    public function getPurchasableVariations(array $pieceSpec, string $algo): array
    {
        /**
         * METERAGE = nominal_length
         * AREA = nominal_length & nominal_width
         * BUNDLE = pack size
         */
        $result = [];

        //METERAGE = nominal_length
        if ($algo === NestingEnums::METERAGE->value) {
            $query = Product::query();
            foreach ($pieceSpec as $field => $value) {
                $query->where($field, $value);
            }

            $result = $query->pluck('nominal_length')
                ->unique()
                ->toArray();
        }
        //AREA = nominal_length & nominal_width
        if ($algo === NestingEnums::AREA->value) {
            //todo 2D no area nesting yet

        }
        //BUNDLE = pack size
        if ($algo === NestingEnums::BUNDLE->value) {

            $query = Product::query();
            foreach ($pieceSpec as $field => $value) {
                $query->where($field, $value);
            }
            $allPacks = $query->get(['pack_size_1', 'pack_size_2', 'pack_size_3'])->toArray();

            $result = isset($allPacks[0])
                ? array_unique(array_values($allPacks[0]))
                : null;
        }

        return $result;
    }

    public function meterageAlgorithm(object $newPieceSpec, array $cutLengthsRequired, array $purchasableStockLengths, array $offcutInventoryLengths, array $lettersProjectArray): array
    {
        /**
         * STEP 1: use & optimised offcuts
         *  1A) Offcut candidates are where an offcut uses 60-100%
         *  1B) Randomly select a candidate
         *  1C) Calculate efficiency
         *  1D) Iterate 10,000 times and choose the highest efficiency result
         *  1E) Cut the offcut into new offcuts
         *  1F) Recycle when offcuts are below threshold, say 1000mm.
         *
         * STEP 2: use & optimised new stock
         *  "The Least Bins packing problem" with random iterations of choosing stock length
         *  2A) Remove pieces that have been allocated to offcuts
         *  2B) Sort the cut lengths in descending order to prioritize fitting large pieces first.
         *  2C) Start with an empty list of bins
         *  2D) Fitting into bins: "First-Fit Decreasing" = place each item into the first available bin that has enough space.
         *  2E) If no existing bin can accommodate it, create a new bin. (randomly choose an available size)
         *  2F) Iterate 10,000 times and choose the highest efficiency result
         */

        /**
         * STEP 1
         */
        $depletableOffcutInventoryLengths = $offcutInventoryLengths;

        $results = [];
        for ($i = 1; $i <= 10; $i++) { //config('env.nesting_iterations')
            $utilisedOffcutBars = [];

            //1A) Offcut candidates are where an offcut uses 60-100%
            foreach ($cutLengthsRequired as $cut) {
                $cutLength = (int) $cut['length'];
                $projectId = (int) $cut['project'];

                $candidates = [];
                foreach($depletableOffcutInventoryLengths as $index => $offcutInventoryLength){
                    //Candidate range
                    $max = $offcutInventoryLength;
                    $min = $offcutInventoryLength * 0.6; //e.g 1000mm offcut can cut a 610mm piece from it

                    if($cutLength >= $min && $cutLength <= $max){
                        $candidates[] = [
                            "length" => $offcutInventoryLength,
                            "index" => $index,
                        ];
                    }
                }
                if(count($candidates) === 0){
                    continue; //skip
                }

                //1B) Randomly select a candidate
                $randomCandidateKey = array_rand($candidates);
                $offcutLength = $candidates[$randomCandidateKey]["length"];

                $utilisedOffcutBars[] = [
                    "offcutLength" => $offcutLength,
                    "cutLength" => $cutLength,
                    "reusableLength" => (($offcutLength - $cutLength) > 1000) ? ($offcutLength - $cutLength) : 0,
                    "scrapLength" => (($offcutLength - $cutLength) > 1000) ? 0 : ($offcutLength - $cutLength),
                    "project" => $projectId,
                ];

                //Remove chosen item from group
                $index = $candidates[$randomCandidateKey]["index"];
                unset($depletableOffcutInventoryLengths[$index]);
            }

            //1C) Calculate efficiency
            $totalOffcutsLength = 0;
            $totalScrapLength = 0;
            foreach($utilisedOffcutBars as $utilisedOffcutBar){
                $totalOffcutsLength = $totalOffcutsLength + $utilisedOffcutBar["offcutLength"];
                $totalScrapLength = $totalScrapLength + $utilisedOffcutBar["scrapLength"];
            }
            $totalUsed = $totalOffcutsLength - $totalScrapLength;
            $efficiency = $totalOffcutsLength > 0
                ? round(($totalUsed/$totalOffcutsLength)*100)
                : 0;

            $results[$efficiency] = $utilisedOffcutBars;
        }

        //1D) Iterate 10,000 times and choose the highest efficiency result
        $highestEfficiencyKey = max(array_keys($results));
        $lowestEfficiencyKey = min(array_keys($results));
        $bestResult = $results[$highestEfficiencyKey];

        //todo debug
        if($newPieceSpec->product_derived_label === "75x50x2.5 RHS"){
            dd($highestEfficiencyKey,$lowestEfficiencyKey,$bestResult);
        }

        // 1E) Cut the offcut into new offcuts
        //todo

        // 1F) Recycle when offcuts are below threshold, say 1000mm.
        //todo

        /**
         * STEP 2
         */
        /*
         * 2A) Remove pieces that have been allocated to offcuts
         */
        //todo
        //$cutLengthsRequired

        /*
         * 2B) Sort the cut lengths in descending order to prioritize fitting large pieces first.
         */
        $cutLengthsRequired = $this->sortCutLengthsDescending($cutLengthsRequired);

        $results = [];
        for ($i = 1; $i <= config('env.nesting_iterations'); $i++) {
            // 2C) Start with an empty list of bins
            $utilisedBars = [];
            $tooLong = []; // Cuts that cannot be placed in any stock bar

            // Process each cut length
            foreach ($cutLengthsRequired as $cut) {
                /*
                 * 2D) "Best-Fit" = placing an item in the bin that leaves the least remaining space.
                 * Try to place the cut into current utilised stock bars
                 * Note that '&$stock' means that '$utilisedBars' is updating itself
                 */
                $tryPlaceCutIntoUtilisedBars = $this->tryPlaceCutIntoUtilisedBars($cut, $utilisedBars, $lettersProjectArray);
                $utilisedBars = $tryPlaceCutIntoUtilisedBars["utilisedBars"];
                $placed = $tryPlaceCutIntoUtilisedBars["placed"];

                /*
                 * 2E) If no existing bin can accommodate it, create a new bin. (randomly choose an available size)
                 */
                if (! $placed) {
                    $newStockPlaced = false;

                    //Random choose available stock length that's big enough
                    $purchasableStockLengthsLongEnough = [];
                    foreach($purchasableStockLengths as $purchasableStockLength){
                        if ($purchasableStockLength >= $cut['length']) {
                            $purchasableStockLengthsLongEnough[] = $purchasableStockLength;
                        }
                    }
                    if(count($purchasableStockLengthsLongEnough) > 0){
                        //new stock placed
                        $newStockPlaced = true;

                        //Random length
                        $randomKey = array_rand($purchasableStockLengthsLongEnough);
                        $randomStockLength = $purchasableStockLengthsLongEnough[$randomKey];

                        //Add new stock bar
                        $utilisedBars = $this->addNewStockBar(
                            $cut,
                            $randomStockLength, //todo include offcuts
                            $utilisedBars,
                            $lettersProjectArray
                        );
                    }

                    // If no new stock bar can accommodate the cut, add it to unfit cuts
                    if (! $newStockPlaced) {
                        $tooLong[] = [
                            'project' => $cut['project'],
                            'length' => $cut['length'],
                            'letter' => $lettersProjectArray[$cut['project']],
                        ];
                    }
                }
            }


            $totalPurchasedMaterial = 0;
            $totalWaste = 0;
            foreach($utilisedBars as $utilisedBar){
                $totalPurchasedMaterial = $totalPurchasedMaterial + $utilisedBar["bar_length"];
                $totalWaste = $totalWaste + $utilisedBar["waste"];
            }
            $totalUsedMaterial = $totalPurchasedMaterial - $totalWaste;
            $efficiency = round($totalUsedMaterial/$totalPurchasedMaterial*100);

            $results[$efficiency] = [
                "utilisedBars" => $utilisedBars,
                "tooLong" => $tooLong,
                "sums" => [
                    "totalPurchasedMaterial" => $totalPurchasedMaterial,
                    "totalUsedMaterial" => $totalUsedMaterial,
                    "totalWaste" => $totalWaste,
                ],
            ];
        }

        //2F) Iterate 10,000 times and choose the highest efficiency result
        $highestEfficiencyKey = max(array_keys($results));
        $utilisedBars = $results[$highestEfficiencyKey]["utilisedBars"];
        $tooLong = $results[$highestEfficiencyKey]["tooLong"];
        $sums = $results[$highestEfficiencyKey]["sums"];

        /**
         * Consolidate utilised stock bars that are the same (same length and cuts array)
         */
        $orderList = $this->orderList($utilisedBars);
        $utilisedBars = $this->consolidateStockNestingResults($utilisedBars);

        return [
            'utilisedBars' => $utilisedBars,
            'tooLong' => $tooLong,
            'orderList' => $orderList,
            'totals' => [
                'totalPurchasedMaterial' => $sums["totalPurchasedMaterial"],
                'totalUsedMaterial' => $sums["totalUsedMaterial"],
                'totalWaste' => $sums["totalWaste"],
            ],
        ];
    }

    private function sortCutLengthsDescending(array $cutLengthsRequired): array
    {
        usort($cutLengthsRequired, function ($a, $b) {
            return $b['length'] <=> $a['length']; //descending order
        });

        return $cutLengthsRequired;
    }

    private function addNewStockBar(array $cut, int $barLength, array $utilisedBars, array $lettersProjectArray): array
    {
        /*
         * "barLength" could be purchase stock length or an offcut
         */
        $cutLength = (int) $cut['length'];
        $projectId = $cut['project'];

        $utilisedBars[] = [
            'bar_length' => $barLength,
            'waste' => $barLength - $cutLength,
            'pieces' => [[
                'cutLength' => $cutLength,
                'projectId' => $projectId,
                'letter' => $lettersProjectArray[$projectId],
            ]],
        ];

        return $utilisedBars;
    }

    private function tryPlaceCutIntoUtilisedBars(array $cut, array $utilisedBars, array $lettersProjectArray): array
    {
        $placed = false;

        $cutLength = (int) $cut['length'];
        $projectId = (int) $cut['project'];

        //Check all currently utilised stock bars
        foreach ($utilisedBars as &$stockBar) {
            //If the cut fits into the 'waste' (remaining)
            if ($stockBar['waste'] >= $cutLength) {
                //Add cut to 'pieces' of this stock bar
                $stockBar['pieces'][] = [
                    'cutLength' => $cutLength,
                    'projectId' => $projectId,
                    'letter' => $lettersProjectArray[$projectId],
                ];

                //Update 'waste' (remaining)
                $stockBar['waste'] = $stockBar['waste'] - $cutLength;

                //Mark as placed
                $placed = true;

                //Stop searching utilised stock bars
                break;
            }
        }

        return [
            "utilisedBars" => $utilisedBars,
            "placed" => $placed,
        ];
    }

    public function bundleAlgorithm(int $totalQty, array $boxSizes): array
    {
        $originalQty = $totalQty;

        // Sort the box sizes in descending order
        rsort($boxSizes);

        //Remove any empty values
        $boxSizes = array_filter($boxSizes, function ($value) {
            return $value !== '' && $value !== null;
        });

        $boxCounts = []; // To store the number of each box size used
        foreach ($boxSizes as $boxSize) {
            $boxSize = (int) $boxSize;

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
            'totalBought' => $totalBought,
            'efficiency' => ($originalQty / $totalBought * 100),
            'boxes' => $boxCounts,
        ];
    }

    public function consolidateStockNestingResults(array $utilisedBars): array
    {
        /**
         * This list is unique stock length cuts.
         * If 2 items area identical except the project refs are different, they'll be treated as different.
         */
        // Step 1: Serialize each array
        $serialized = array_map('serialize', $utilisedBars);

        // Step 2: Count occurrences
        $counted = array_count_values($serialized);

        // Step 3: Unserialize keys to get original arrays
        $result = [];
        foreach ($counted as $key => $count) {
            $result[] = [
                'count' => $count,
                'result' => unserialize($key),
            ];
        }

        return $result;
    }

    public function orderList(array $utilisedBars): array
    {
        /**
         * This list is unique stock length.
         * If 2 items area identical except the project refs are different, they'll be treated as the same.
         */
        //Remove the project ID so they consolidate disregarding project refs
        $newResult = [];
        foreach ($utilisedBars as $bar) {
            unset($bar['waste']);
            unset($bar['pieces'][0][1]);
            $newResult[] = $bar['bar_length'];
        }

        // Step 1: Serialize each array
        $serialized = array_map('serialize', $newResult);

        // Step 2: Count occurrences
        $counted = array_count_values($serialized);

        // Step 3: Unserialize keys to get original arrays
        $result = [];
        foreach ($counted as $key => $count) {
            $result[] = [
                'count' => $count,
                'result' => unserialize($key),
            ];
        }

        return $result;
    }

    public function getNestingGroups(): array
    {
        $nestingGroups = [];

        //Meterage
        $products = Product::query()
            ->where('nesting_algo', NestingEnums::METERAGE->value)
            ->pluck('product_category')
            ->unique()
            ->toArray();
        $nestingGroups[NestingEnums::METERAGE->value] = array_values($products);

        //Area
        $products = Product::query()
            ->where('nesting_algo', NestingEnums::AREA->value)
            ->pluck('product_category')
            ->unique()
            ->toArray();
        $nestingGroups[NestingEnums::AREA->value] = array_values($products);

        //Bundle
        $products = Product::query()
            ->where('nesting_algo', NestingEnums::BUNDLE->value)
            ->pluck('product_category')
            ->unique()
            ->toArray();
        $nestingGroups[NestingEnums::BUNDLE->value] = array_values($products);

        return $nestingGroups;
    }

    public function nestingViewData(string $type, Business $business, ?Batch $batch): array
    {
        $piecesNested = null;
        $projectsForBatching = null;
        $piecesGroupedBySupplierGroup = null;
        $usageStats = null;

        //Batch (after batch object exists)
        if ($type === 'BATCH') {
            //Projects in batch
            $projectsForBatching = $batch->projects();

            //Pieces ready for batching
            $piecesInBatch = $batch->pieces;

            //Letter-project array
            $lettersProjectArray = $this->getLetterProjectArray($piecesInBatch);

            //Pieces nested
            $piecesNested = $this->piecesNested($piecesInBatch, $lettersProjectArray);

            //Nesting stats
            $usageStats = $this->usageStats($piecesNested);

            //Pieces grouped by supplier group
            $piecesGroupedBySupplierGroup = $this->piecesGroupedBySupplierGroup($piecesNested, $business);
        }
        //Suggested (pre-batch at nesting phase)
        if ($type === 'SUGGESTED') {
            //Projects ready for batching
            $projectsForBatching = $business->projectsReadyForBatching();

            //Pieces ready for batching
            $piecesReadyForBatching = $this->piecesReadyForBatching($business);

            //Letter-project array
            $lettersProjectArray = $this->getLetterProjectArray($piecesReadyForBatching);

            //Pieces nested
            $piecesNested = $this->piecesNested($piecesReadyForBatching, $lettersProjectArray);

            //Nesting stats
            $usageStats = $this->usageStats($piecesNested);

            //Pieces grouped by supplier group
            $piecesGroupedBySupplierGroup = $this->piecesGroupedBySupplierGroup($piecesNested, $business);
        }

        return [
            'pieces' => $piecesNested,
            'projectsReadyForBatching' => ProjectResource::collection($projectsForBatching),
            'piecesGroupedBySupplierGroup' => $piecesGroupedBySupplierGroup,
            'usage' => $usageStats,
            'type' => $type,
        ];
    }

    /**
     * Collections
     */
    public function piecesReadyForBatching(Business $business): Collection
    {
        //todo: timeline and status criteria needed
        $projectsReadyForBatching = $business->projectsReadyForBatching();

        $projectsForQuotingIds = $projectsReadyForBatching
            ->pluck('id')
            ->toArray();

        return Piece::query()
            ->whereIn('project_id', $projectsForQuotingIds)
            ->readyToBatch()
            ->get();
    }

    public function nesting(string $nestingAlgoLabel, Collection $allPieces, array $lettersProjectArray): Collection
    {
        $result = [];

        //METERAGE
        if ($nestingAlgoLabel === NestingEnums::METERAGE->value) {
            $result = $this->nestingMeterageAlgo($allPieces,$lettersProjectArray);
        }
        //AREA
        if ($nestingAlgoLabel === NestingEnums::AREA->value) {
            $result = $this->nestingAreaAlgo($allPieces);
        }
        //BUNDLE
        if ($nestingAlgoLabel === NestingEnums::BUNDLE->value) {
            $result = $this->nestingBundleAlgo($allPieces);
        }

        return collect($result);
    }

    private function nestingMeterageAlgo(Collection $allPieces, array $lettersProjectArray): array
    {
        $result = [];

        $productService = new ProductService;

        //Group pieces by product category
        $piecesByProductCategory = $allPieces->groupBy('product_category');
        foreach ($piecesByProductCategory as $productCategory => $pieces) {
            $generalProductDefinition = $productService->generalProductDefinition($productCategory);

            //Product definition
            $allFieldsIndividual = [];
            foreach ($generalProductDefinition['mandatory'] as $field) {
                $allFieldsIndividual[$field] = false;
            }

            //Fields
            $fieldLabels = array_keys($allFieldsIndividual);

            //Unique piece specs
            $uniquePieceSpecs = Piece::select($fieldLabels)
                ->whereIn('id', $pieces->pluck('id')->toArray())
                ->where('product_category', $productCategory)
                ->distinct()
                ->get()
                ->toArray();

            //Loop each unique piece specs
            foreach ($uniquePieceSpecs as $uniquePieceSpec) {
                $result[] = $this->buildMeterageProductSpec($allPieces,$uniquePieceSpec,$lettersProjectArray);
            }
        }

        return $result;
    }

    private function buildMeterageProductSpec($pieces,$uniquePieceSpec,$lettersProjectArray): object
    {
        /**
         * Build a piece/product spec
         */

        //Services
        $productService = new ProductService;

        /*
         * New Piece spec
         */
        $newPieceSpec = (object) $uniquePieceSpec;

        /*
         * Derived product label. e.g "200PFC SS316"
         */
        $newPieceSpec->product_derived_label = $productService->getDerivedProductLabel($uniquePieceSpec);

        /*
         * Nesting algorithm
         */
        $newPieceSpec->algo = NestingEnums::METERAGE->value;

        /*
         * Get pieces that match spec
         */
        foreach ($uniquePieceSpec as $field => $value) {
            $pieces = $pieces->where($field, $value);
        }
        $pieces->sortBy('actual_length');

        $piecesArray = [];
        foreach ($pieces as $piece) {
            $piecesArray[] = [
                'project' => $piece->project()->first(),
                'length' => $piece->actual_length,
                'nominal_units' => $piece->nominal_units,
                'quantity' => $piece->actual_qty,
            ];
        }
        $newPieceSpec->pieces = $piecesArray;

        /*
         * Purchasable stock lengths
         */
        $purchasableStockLengths = $this->getPurchasableVariations($uniquePieceSpec, NestingEnums::METERAGE->value);
        $newPieceSpec->purchasableLengths = $purchasableStockLengths;

        /*
         * Offcut inventory lengths
         */
        $offcutInventoryLengths = [6000,1500,1200]; //todo from THIS product spec
        $newPieceSpec->offcutInventoryLengths = $offcutInventoryLengths;

        /*
         * Nesting
         */
        $cutLengthsRequired = [];
        foreach ($pieces as $piece) {
            for ($i = 0; $i < (int) $piece->actual_qty; $i++) {
                $cutLengthsRequired[] = [
                    'project' => $piece->project()->first()->id,
                    'length' => $piece->actual_length,
                ];
            }
        }
        $newPieceSpec->nested = $this->meterageAlgorithm(
            $newPieceSpec,
            $cutLengthsRequired,
            $purchasableStockLengths,
            $offcutInventoryLengths,
            $lettersProjectArray
        );

        return $newPieceSpec;
    }

    private function nestingAreaAlgo(Collection $allPieces): array
    {
        $result = [];

        $productService = new ProductService;

        //Group pieces by product category
        $piecesByProductCategory = $allPieces->groupBy('product_category');
        foreach ($piecesByProductCategory as $productCategory => $pieces) {
            $generalProductDefinition = $productService->generalProductDefinition($productCategory);

            //Product definition
            $allFieldsIndividual = [];
            foreach ($generalProductDefinition['mandatory'] as $field) {
                $allFieldsIndividual[$field] = false;
            }

            //Fields
            $fieldLabels = array_keys($allFieldsIndividual);

            //Unique piece specs
            $uniquePieceSpecs = Piece::select($fieldLabels)
                ->whereIn('id', $pieces->pluck('id')->toArray())
                ->where('product_category', $productCategory)
                ->distinct()
                ->get()
                ->toArray();

            //Loop each unique piece specs
            foreach ($uniquePieceSpecs as $uniquePieceSpec) {
                /*
                 * Get pieces that match spec
                 */
                $pieces = $allPieces;
                foreach ($uniquePieceSpec as $field => $value) {
                    $pieces = $pieces->where($field, $value);
                }
                $pieces->sortBy('product_category'); //todo something more useful

                //Material spec
                $appended = (object) $uniquePieceSpec;

                //Derived product label. e.g "200PFC SS316"
                $appended->product_derived_label = $productService->getDerivedProductLabel($uniquePieceSpec);

                //Nesting algorithm
                $appended->algo = NestingEnums::AREA->value;

                $stockLengths = [];
                $piecesArray = [];

                //todo loop

                $appended->pieces = $piecesArray;
                $appended->purchasable = $stockLengths;
                $appended->nested = []; //todo

                $result[] = $appended;
            }
        }

        return $result;
    }

    private function nestingBundleAlgo(Collection $allPieces): array
    {
        $result = [];

        $productService = new ProductService;

        //Group pieces by product category
        $piecesByProductCategory = $allPieces->groupBy('product_category');
        foreach ($piecesByProductCategory as $productCategory => $pieces) {
            $generalProductDefinition = $productService->generalProductDefinition($productCategory);

            //Product definition
            $allFieldsIndividual = [];
            foreach ($generalProductDefinition['mandatory'] as $field) {
                $allFieldsIndividual[$field] = false;
            }

            //Fields
            $fieldLabels = array_keys($allFieldsIndividual);

            //Unique piece specs
            $uniquePieceSpecs = Piece::select($fieldLabels)
                ->whereIn('id', $pieces->pluck('id')->toArray())
                ->where('product_category', $productCategory)
                ->distinct()
                ->get()
                ->toArray();

            //Loop each unique piece specs
            foreach ($uniquePieceSpecs as $uniquePieceSpec) {
                /*
                 * Get pieces that match spec
                 */
                $pieces = $allPieces;
                foreach ($uniquePieceSpec as $field => $value) {
                    $pieces->where($field, $value);
                }
                $pieces->sortBy('product_category'); //todo something more useful

                //Material spec
                $appended = (object) $uniquePieceSpec;

                //Derived product label. e.g "200PFC SS316"
                $appended->product_derived_label = $productService->getDerivedProductLabel($uniquePieceSpec);

                //Nesting algorithm
                $appended->algo = NestingEnums::BUNDLE->value;

                $piecesArray = [];
                $boxSizes = $this->getPurchasableVariations($uniquePieceSpec, NestingEnums::BUNDLE->value);

                $totalQty = 0;
                foreach ($pieces as $piece) {
                    $piecesArray[] = [
                        'project' => $piece->project()->first(),
                        'length' => null,
                        'nominal_units' => $piece->nominal_units,
                        'quantity' => $piece->actual_qty,
                    ];
                    $totalQty = $totalQty + $piece->actual_qty;
                }

                $appended->pieces = $piecesArray;
                $appended->purchasable = $boxSizes;
                $appended->nested = $this->bundleAlgorithm($totalQty, $boxSizes);

                $result[] = $appended;
            }
        }

        return $result;
    }
}
