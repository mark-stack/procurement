<?php

namespace App\Formatters;

use App\Enums\GradeEnums;
use App\Enums\MaterialEnums;
use App\Enums\NestingEnums;
use App\Enums\ProductEnums;
use App\Http\Resources\ProjectResource;
use App\Models\Batch;
use App\Models\Business;
use App\Models\Offcut;
use App\Models\Piece;
use App\Models\Product;
use App\Models\Scrap;
use App\Services\ProductService;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

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

    public function piecesNested(Collection $pieces, array $lettersProjectArray, Business $business): array
    {
        $groupedByAlgo = $pieces->groupBy('nesting_algo');

        $piecesNested = [];
        foreach ($groupedByAlgo as $nestingAlgoLabel => $pieces) {
            $piecesNested[$nestingAlgoLabel] = $this->nesting($nestingAlgoLabel, $pieces, $lettersProjectArray, $business);
        }

        return $piecesNested;
    }

    public function usageStats(array $piecesNested): array
    {
        /**
         * Sums of material totals, usage, and waste
         */

        $result = [];

        //Loop different supplier groups. e.g "steel merchant"
        foreach ($piecesNested as $algo => $items) {
            //Meterage
            if($algo === NestingEnums::METERAGE->value){
                $totalPurchasedMaterial = 0;
                $totalUsedMaterial = 0;
                $totalReusable = 0;
                $totalScrap = 0;

                //Loop different products. e.g PFC
                foreach ($items as $item) {
                    $item = (array) $item;

                    if (isset($item['nested']['totals'])) {
                        $totals = $item['nested']['totals'];
                        $totalPurchasedMaterial = $totalPurchasedMaterial + $totals["oldStock"]['total'] + $totals["newStock"]['total'];
                        $totalUsedMaterial = $totalUsedMaterial + $totals["oldStock"]['used'] + $totals["newStock"]['used'];
                        $totalReusable = $totalReusable + $totals["oldStock"]['reusable'] + $totals["newStock"]['reusable'];
                        $totalScrap = $totalScrap + $totals["oldStock"]['scrap'] + $totals["newStock"]['scrap'];
                    }
                }

                $result[$algo] = [
                    'totalPurchasedMaterial' => $totalPurchasedMaterial,
                    'totalUsedMaterial' => $totalUsedMaterial,
                    "totalReusable" => $totalReusable,
                    "totalScrap" => $totalScrap,
                    'efficiency' => $totalPurchasedMaterial === 0
                        ? 0
                        : (round(($totalUsedMaterial / $totalPurchasedMaterial * 100),1)),
                ];
            }

            //Bundle
            //todo

            //Area
            //todo
        }

        return $result;
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

    public function getPurchasableVariations(array $pieceSpec, string $algo, Business $business): array
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

            /*
             * 12m length cap
             */
            $result = $query
                ->pluck('nominal_length')
                ->unique()
                ->toArray();

            //Convert all string numbers to integhers
            $result = array_map('intval', $result);

            //12m stock limit (for ease of delivery)
            if($business->cap_12m_stock){
                $result = array_filter(
                    $result,
                    fn($num) => $num <= 12000
                );
            }
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

    public function meterageAlgorithm(
        array $cutLengthsRequired,
        array $purchasableStockLengths,
        array $offcutInventory,
        array $lettersProjectArray,
        Business $business,
        object $newPieceSpec = null,
    ): array
    {
        /**
         * STEP 1: use & optimised offcuts
         *  1A) Offcut candidates are where an offcut uses 60-100%
         *  1B) Randomly select a candidate
         *  1C) Calculate efficiency
         *  1D) Iterate 100 times and choose the highest efficiency result
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
        $iterations = 10;
        $depletableOffcutInventory = $offcutInventory;

        $resultsOffcuts = [];

        //Nesting required cuts into offcut inventory. e.g might cut 3x 900mm from 3,000mm
        $bestResultOffcuts = $this->bestResultOffcuts($cutLengthsRequired,$offcutInventory,$lettersProjectArray, $business);

        /**
         * STEP 2
         */
        /*
         * 2A) Remove pieces from "cutLengthsRequired" that have been allocated to offcuts
         */
        $cutLengthsRequiredAfterOffcutAllocation = $cutLengthsRequired;
        if(count($bestResultOffcuts) > 0){
            foreach($bestResultOffcuts["utilisedOffcutBars"] as $offcutBar){
                //Loop cuts
                foreach($offcutBar["cuts"] as $cut){
                    foreach($cutLengthsRequiredAfterOffcutAllocation as $index => $requiredLength){
                        if($cut["length"] === (int) $requiredLength["length"]){
                            unset($cutLengthsRequiredAfterOffcutAllocation[$index]);
                            break;
                        }
                    }
                }
            }

            $cutLengthsRequiredAfterOffcutAllocation = array_values($cutLengthsRequiredAfterOffcutAllocation);
        }

        /*
         * 2B) Sort the cut lengths in descending order to prioritize fitting large pieces first.
         */
        $cutLengthsRequiredAfterOffcutAllocation = $this->sortCutLengthsDescending($cutLengthsRequiredAfterOffcutAllocation);

        $results = [];

        //1000+ random selection
        for ($i = 1; $i <= config('env.nesting_iterations'); $i++) {
            $singleRun = $this->singleRun(
                $cutLengthsRequiredAfterOffcutAllocation,
                $lettersProjectArray,
                $purchasableStockLengths,
                $business,
                true,
            );

            $results[$singleRun["efficiency"]] = $singleRun["result"];
        }

        //"Best fit" comparison
        $singleRun = $this->singleRun(
            $cutLengthsRequiredAfterOffcutAllocation,
            $lettersProjectArray,
            $purchasableStockLengths,
            $business,
            false,
        );

        $results[$singleRun["efficiency"]] = $singleRun["result"];

        //2F) Iterate 10,000 times and choose the highest efficiency result
        $highestEfficiencyKeyOfNewStock = max(array_keys($results));
        $utilisedBars = $results[$highestEfficiencyKeyOfNewStock]["utilisedBars"];
        $tooLong = $results[$highestEfficiencyKeyOfNewStock]["tooLong"];
        $sums = $results[$highestEfficiencyKeyOfNewStock]["sums"];

        /**
         * Consolidate utilised stock bars that are the same (same length and cuts array)
         */
        $orderList = $this->orderList($utilisedBars);
        $utilisedBars = $this->consolidateStockNestingResults($utilisedBars);

        /*
         * "Effective efficiency"
         * Using offcuts could result in a lower efficiency of the new stock, but that misses the fact offcuts were used.
         * The "Effective efficiency" is total used (used of bought + non-scraped amount of offcuts) / total (bought + offcuts)
         */
        $effectiveUsed = $sums["totalUsedMaterial"] + $bestResultOffcuts["totalUsedOffcuts"];
        $effectiveTotal = $sums["totalPurchasedMaterial"] + $bestResultOffcuts["totalOffcutsLength"];
        $effectiveEfficiency = $effectiveTotal > 0
            ? round(($effectiveUsed/$effectiveTotal*100),1)
            : 0;

        return [
            'utilisedBars' => $utilisedBars,
            "bestResultOffcuts" => $bestResultOffcuts,
            'tooLong' => $tooLong,
            'orderList' => $orderList,
            'totals' => [
                "oldStock" => [
                    "total" => $bestResultOffcuts["totalOffcutsLength"],
                    "used" => $bestResultOffcuts["totalUsedOffcuts"],
                    "unused" => $bestResultOffcuts["totalOffcutsLength"] - $bestResultOffcuts["totalUsedOffcuts"],
                    "reusable" => $bestResultOffcuts["totalReusableLength"],
                    "scrap" => $bestResultOffcuts["totalScrapLength"],
                ],
                "newStock" => [
                    "total" => $sums["totalPurchasedMaterial"],
                    "used" => $sums["totalUsedMaterial"],
                    "unused" => $sums["totalPurchasedMaterial"] - $sums["totalUsedMaterial"],
                    "reusable" => $sums["totalReusable"],
                    "scrap" => $sums["totalScrap"],
                ],
            ],
        ];
    }

    private function bestResultOffcuts(array $cutLengthsRequired, array $offcutInventory, array $lettersProjectArray, Business $business): array
    {
        /**
         * This is holistic nesting instead of piece by piece comparison which is an oversimplification.
         *
         * E.g 3000mm offcut and 3x 900mm pieces required. Simply comparing 900mm to 3000mm would say skip it,
         * but 3x900mm = 2700mm which is viable use of 3000mm
         *
         * if there's multiple nesting possibilities, iterate through them
         */

        //Required cuts from biggest to smallest
        $cutLengthsRequired = $this->sortCutLengthsDescending($cutLengthsRequired);

        //Nesting
        $nestRequiredCutsIntoOffcuts = $this->nestRequiredCutsIntoOffcuts(
            $offcutInventory,
            $cutLengthsRequired,
            $lettersProjectArray,
        );

        $utilisedOffcutBars = [];
        foreach($nestRequiredCutsIntoOffcuts as $offcut){
            $offcutLength = $offcut["length"];
            $usedLength = $offcutLength - $offcut["unused"];

            $utilisedOffcutBars[] = [
                "offcutLength" => $offcut["length"],
                "cutLength" => $usedLength,
                "reusableLength" => (($offcutLength - $usedLength) > $business->scrap_threshold_mm) ? ($offcutLength - $usedLength) : 0,
                "scrapLength" => (($offcutLength - $usedLength) > $business->scrap_threshold_mm) ? 0 : ($offcutLength - $usedLength),
                "offcutId" => $offcut["offcut_id"],
                "batchFromId" => $offcut["batch_from_id"],
                "scrap_threshold_mm" => $business->scrap_threshold_mm,
                "cuts" => $offcut["cuts"], //length, projectId, piece_id, letter
            ];
        }

        /*
         * Calculate efficiency
         * Efficiency is amount of offcuts used that's not scraped. So it factors in reusable trimmings
         */
        $totalOffcutsLength = 0;
        $totalScrapLength = 0;
        $totalUsedOffcuts = 0;
        $totalReusableLength = 0;
        foreach($utilisedOffcutBars as $utilisedOffcutBar){
            $totalOffcutsLength = $totalOffcutsLength + $utilisedOffcutBar["offcutLength"];
            $totalUsedOffcuts = $totalUsedOffcuts + $utilisedOffcutBar["cutLength"];
            $totalScrapLength = $totalScrapLength + $utilisedOffcutBar["scrapLength"];
            $totalReusableLength = $totalReusableLength + $utilisedOffcutBar["reusableLength"];
        }

        return [
            "utilisedOffcutBars" => $utilisedOffcutBars,
            "totalOffcutsLength" => $totalOffcutsLength,
            "totalUsedOffcuts" => $totalUsedOffcuts,
            "totalScrapLength" => $totalScrapLength,
            "totalReusableLength" => $totalReusableLength,
        ];
    }

    private function nestRequiredCutsIntoOffcuts(
        array $offcutInventory,
        array $cutLengthsRequired,
        array $lettersProjectArray,
    ): array
    {
        /**
         * Take a list of required cuts and a list of available offcuts, and nest them. It includes cutting the offcut.
         */

        $utilisedOffcuts = [];

        $depletableOffcutInventory = $offcutInventory;

        foreach ($cutLengthsRequired as $cut) {
            $placed = false;

            $cutLength = (int) $cut['length'];
            $projectId = (int) $cut['project'];
            $pieceId = $cut["piece_id"];

            // Try to fit the cut into an existing bar
            foreach ($utilisedOffcuts as &$bar) {
                if ($bar['unused'] >= $cutLength) {

                    //dd();
                    $cutData = [
                        "length" => $cutLength,
                        "projectId" => $projectId,
                        "piece_id" => $pieceId,
                        'letter' => $lettersProjectArray[$projectId],
                    ];

                    $bar['cuts'][] =  $cutData;
                    $bar['unused'] -= $cutLength;
                    $placed = true;
                    break;
                }
            }

            // If not placed, use a new offcut bar
            if (!$placed) {
                $offcutSelected = false;
                foreach ($depletableOffcutInventory as $index => $offcutData) {
                    $offcutLength = $offcutData['length'];

                    if ($offcutLength >= $cutLength) {
                        $offcutSelected = true;

                        $cutData = [
                            "length" => $cutLength,
                            "projectId" => $projectId,
                            "piece_id" => $pieceId,
                            'letter' => $lettersProjectArray[$projectId],
                        ];

                        $utilisedOffcuts[] = [
                            "length" => $offcutData["length"],
                            "offcut_id" => $offcutData["id"],
                            'unused' => $offcutLength - $cutLength,
                            "batch_from_id" => $offcutData["batch_from_id"],
                            'cuts' => [$cutData],
                        ];
                        break;
                    }
                }

                //Remove used offcut
                if($offcutSelected){
                    unset($depletableOffcutInventory[$index]);
                }
            }
        }

        return $utilisedOffcuts;
    }

    private function singleRun(
        array $cutLengthsRequiredAfterOffcutAllocation,
        array $lettersProjectArray,
        array $purchasableStockLengths,
        Business $business,
        bool $random,

    ): array
    {
        // 2C) Start with an empty list of bins
        $utilisedBars = [];
        $tooLong = []; // Cuts that cannot be placed in any stock bar

        // Process each cut length
        foreach ($cutLengthsRequiredAfterOffcutAllocation as $cut) {
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

                    /*
                     * Random
                     */
                    $selectedStockLength = null;
                    if($random){
                        //Random length
                        $randomKey = array_rand($purchasableStockLengthsLongEnough);
                        $selectedStockLength = $purchasableStockLengthsLongEnough[$randomKey];
                    }
                    /*
                     * "Best fit" (option with least waste)
                     */
                    else{
                        $selectedStockLength = min($purchasableStockLengthsLongEnough);
                    }

                    //Add new stock bar
                    $utilisedBars = $this->addNewStockBar(
                        $cut,
                        $selectedStockLength,
                        $utilisedBars,
                        $lettersProjectArray,
                        $business,
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
        $totalUnused = 0;
        foreach($utilisedBars as $utilisedBar){
            $totalPurchasedMaterial = $totalPurchasedMaterial + $utilisedBar["bar_length"];
            $totalUnused = $totalUnused + $utilisedBar["unused"];
        }
        $totalUsedMaterial = $totalPurchasedMaterial - $totalUnused;
        $efficiency = $totalUsedMaterial > 0
            ? round(($totalUsedMaterial/$totalPurchasedMaterial*100),1)
            : 0;

        return [
            "efficiency" => $efficiency,
            "result" => [
                "utilisedBars" => $utilisedBars,
                "tooLong" => $tooLong,
                "sums" => [
                    "totalPurchasedMaterial" => $totalPurchasedMaterial,
                    "totalUsedMaterial" => $totalUsedMaterial,
                    "totalUnused" => $totalUnused,
                    "totalReusable" => $totalUnused > $business->scrap_threshold_mm ? $totalUnused : 0,
                    "totalScrap" => $totalUnused > $business->scrap_threshold_mm ? 0 : $totalUnused,
                ],
            ]
        ];
    }

    private function generateUniqueCode(&$usedCodes): string
    {
        do {
            $code = '';
            for ($i = 0; $i < 4; $i++) {
                $code .= chr(rand(65, 90)); // Generate random uppercase letter (A-Z)
            }
        } while (in_array($code, $usedCodes)); // Ensure uniqueness

        $usedCodes[] = $code; // Store used code
        return $code;
    }

    private function sortCutLengthsDescending(array $cutLengthsRequired): array
    {
        usort($cutLengthsRequired, function ($a, $b) {
            return $b['length'] <=> $a['length']; //descending order
        });

        return $cutLengthsRequired;
    }

    private function addNewStockBar(
        array $cut,
        int $barLength,
        array $utilisedBars,
        array $lettersProjectArray,
        Business $business
    ): array
    {
        /*
         * "barLength" could be purchase stock length or an offcut
         */
        $cutLength = (int) $cut['length'];
        $projectId = $cut['project'];
        $unused = $barLength - $cutLength;

        $utilisedBars[] = [
            'bar_length' => $barLength,
            'unused' => $unused,
            "scrap_threshold_mm" => $business->scrap_threshold_mm,
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
            //If the cut fits into the 'unused' (remaining)
            if ($stockBar['unused'] >= $cutLength) {
                //Add cut to 'pieces' of this stock bar
                $stockBar['pieces'][] = [
                    'cutLength' => $cutLength,
                    'projectId' => $projectId,
                    'letter' => $lettersProjectArray[$projectId],
                ];

                //Update 'unused' (remaining)
                $stockBar['unused'] = $stockBar['unused'] - $cutLength;

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
            unset($bar['unused']);
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
        $lettersProjectArray = null;

        //Batch (after batch object exists)
        if ($type === 'BATCH') {
            //Projects in batch
            $projectsForBatching = $batch->projects();

            //Pieces nested (from saved)
            //todo refactor this to collecting 'BAR' and 'OFFCUT' items
            $piecesNested = unserialize($batch->nested_state);

            //Letter-project array
            $pieces = [];
            foreach($projectsForBatching as $project){
                foreach($project->pieces as $piece){
                    $pieces[] = $piece;
                }
            }
            $lettersProjectArray = $this->getLetterProjectArray(collect($pieces));

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

            //Pieces nested (generated)
            $piecesNested = $this->piecesNested($piecesReadyForBatching, $lettersProjectArray, $business);

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
            "lettersProjectArray" => $lettersProjectArray,
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

    public function nesting(string $nestingAlgoLabel, Collection $allPieces, array $lettersProjectArray, Business $business): Collection
    {
        $result = [];

        //METERAGE
        if ($nestingAlgoLabel === NestingEnums::METERAGE->value) {
            $result = $this->nestingMeterageAlgo($allPieces,$lettersProjectArray,$business);
        }
        //AREA
        if ($nestingAlgoLabel === NestingEnums::AREA->value) {
            $result = $this->nestingAreaAlgo($allPieces,$business);
        }
        //BUNDLE
        if ($nestingAlgoLabel === NestingEnums::BUNDLE->value) {
            $result = $this->nestingBundleAlgo($allPieces,$business);
        }

        return collect($result);
    }

    private function nestingMeterageAlgo(Collection $allPieces, array $lettersProjectArray, Business $business): array
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
                $result[] = $this->buildMeterageProductSpec($allPieces,$uniquePieceSpec,$lettersProjectArray, $business);
            }
        }

        return $result;
    }

    private function buildMeterageProductSpec(Collection $pieces, array $uniquePieceSpec, array $lettersProjectArray, Business $business): object
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
        $purchasableStockLengths = $this->getPurchasableVariations($uniquePieceSpec, NestingEnums::METERAGE->value,$business);
        $newPieceSpec->purchasableLengths = $purchasableStockLengths;

        /*
         * Offcut inventory lengths
         */
        $offcutInventory = Offcut::query()
            //Attributes
            ->where('product_category',$newPieceSpec->product_category)
            ->where('material',$newPieceSpec->material ?? null)
            ->where('grade',$newPieceSpec->grade ?? null)
            ->where('surface',$newPieceSpec->surface ?? null)
            ->where('nominal_length',$newPieceSpec->nominal_length ?? null)
            ->where('precise_length',$newPieceSpec->precise_length ?? null)
            ->where('nominal_width',$newPieceSpec->nominal_width ?? null)
            ->where('precise_width',$newPieceSpec->precise_width ?? null)
            ->where('nominal_height',$newPieceSpec->nominal_height ?? null)
            ->where('precise_height',$newPieceSpec->precise_height ?? null)
            ->where('wall',$newPieceSpec->wall ?? null)

            //Availability
            ->where("batch_to_id",null)

            ->get()
            ->toArray();
        $newPieceSpec->offcutInventoryLengths = $offcutInventory;


        /*
         * Nesting
         */
        $cutLengthsRequired = [];
        foreach ($pieces as $piece) {
            for ($i = 0; $i < (int) $piece->actual_qty; $i++) {
                $cutLengthsRequired[] = [
                    'project' => $piece->project()->first()->id,
                    "piece_id" => $piece->id,
                    'length' => $piece->actual_length,
                ];
            }
        }

        $newPieceSpec->nested = $this->meterageAlgorithm(
            $cutLengthsRequired,
            $purchasableStockLengths,
            $offcutInventory,
            $lettersProjectArray,
            $business,
            $newPieceSpec,
        );

        return $newPieceSpec;
    }

    private function nestingAreaAlgo(Collection $allPieces, Business $business): array
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

    private function nestingBundleAlgo(Collection $allPieces, Business $business): array
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
                $boxSizes = $this->getPurchasableVariations($uniquePieceSpec, NestingEnums::BUNDLE->value, $business);

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
