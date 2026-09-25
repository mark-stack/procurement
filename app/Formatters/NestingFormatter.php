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
use Random\Engine\Mt19937;
use Random\Randomizer;

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

        /*
         * Always report a meterage block, even when nothing in this nest is meterage (a bolts-only
         * batch, say). Both checks() and the nesting screens read usage.METERAGE unconditionally.
         */
        $result = [
            NestingEnums::METERAGE->value => [
                'totalPurchasedMaterial' => 0,
                'totalUsedMaterial' => 0,
                "totalReusable" => 0,
                "totalScrap" => 0,
                'efficiency' => 0,
            ],
        ];

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

    public function checks(array $piecesNested, array $usageStats, Business $business): array
    {
        /**
         1) Total length of input pieces = total length of output cuts
         2) Qty of input pieces = qty of output cuts
         3) Efficiency 70%+
         4) a cut longer than max stock length is categorised as "too long"
         5) total offcuts used < total available
         6) new offcuts + scrap = total unused
         7) Each bar: sum of cuts less than bar
         8) total used + total new offcuts + scrap - total used offcuts = total bought + used offcuts
         9) Using more old stock than scraping
         10)
         */

        $usageStatsMeterage = $usageStats[NestingEnums::METERAGE->value];
        $meteragePieces = $piecesNested[NestingEnums::METERAGE->value] ?? [];

        /*
         * 3) Efficiency 70%+
         */
        $efficiency = $usageStatsMeterage["efficiency"] <= 100 && $usageStatsMeterage["efficiency"] > 70;
        $efficiency_number = $usageStatsMeterage["efficiency"];

        /*
         * Count passing individual products
         */
        $countQ1 = 0;
        $numberQ1 = 0;

        $countQ2 = 0;
        $numberQ2 = 0;

        $countQ4 = 0;

        $countQ5 = 0;
        $numberQ5 = 0;

        $countQ6 = 0;
        $numberQ6 = 0;

        $countQ7 = 0;

        $countQ8 = 0;

        $countQ9 = 0;

        foreach($meteragePieces as $product){
            /*
             * 1) Total length of input pieces = total length of output cuts
             */
            //Sum pieces
            $sumPieces = 0;
            foreach($product->pieces as $piece){
                $sumPieces = $sumPieces + ($piece["length"] * $piece["quantity"]);
            }

            //Sum cuts
            $sumCuts = 0;
            foreach($product->nested['utilisedBars'] as $utilisedBar){
                $qty = $utilisedBar["count"];
                $cuts = $utilisedBar["result"]["pieces"];
                foreach($cuts as $cut){
                    $sumCuts = $sumCuts + ($qty * $cut["cutLength"]);
                }
            }

            //Sum 'too long'
            $sumTooLong = 0;
            foreach($product->nested["tooLong"] as $tooLong){
                $sumTooLong = $sumTooLong + $tooLong["length"];
            }

            //Sum offcut cuts
            $sumOffcutCuts = 0;
            $utilisedOffcutBars = $product->nested["bestResultOffcuts"]["utilisedOffcutBars"];
            foreach($utilisedOffcutBars as $utilisedOffcutBar){
                foreach($utilisedOffcutBar["sourceOffcut"]["cuts"] as $cut){
                    $sumOffcutCuts = $sumOffcutCuts + $cut["length"];
                }
            }

            if($sumPieces === ($sumCuts + $sumTooLong + $sumOffcutCuts)){
                $countQ1++;
            }
            $numberQ1 = $numberQ1 + $sumCuts;

            /*
             * 2) Qty of input pieces = qty of output cuts
             */
            //Qty pieces
            $qtyPieces = 0;
            foreach($product->pieces as $piece){
                $qtyPieces = $qtyPieces + $piece["quantity"];
            }

            //Qty cuts
            $qtyCuts = 0;
            foreach($product->nested['utilisedBars'] as $utilisedBar){
                $qty = $utilisedBar["count"];
                $cuts = $utilisedBar["result"]["pieces"];
                $qtyCuts = $qtyCuts + ($qty * count($cuts));
            }

            //Qty 'too long'
            $qtyTooLong = count($product->nested["tooLong"]);

            //Sum offcut cuts
            $qtyOffcutCuts = 0;
            foreach($utilisedOffcutBars as $utilisedOffcutBar){
                $qtyOffcutCuts = $qtyOffcutCuts + count($utilisedOffcutBar["sourceOffcut"]["cuts"]);
            }

            if($qtyPieces === ($qtyCuts + $qtyTooLong + $qtyOffcutCuts)){
                $countQ2++;
            }
            $numberQ2 = $numberQ2 + $qtyCuts;

            /*
             * 4) a cut longer than max stock length is categorised as "too long"
             */
            $tooLong = $product->nested["tooLong"];
            if(count($tooLong) === 0){
                $countQ4++;
            }
            else{
                $countLonger = 0;
                foreach($tooLong as $item){
                    $length = (int) $item["length"];
                    if(count($product->purchasableLengths) > 0 && $length > max($product->purchasableLengths)){
                        $countLonger++;
                    }
                }

                if($countLonger === count($tooLong)){
                    $countQ4++;
                }
            }

            /*
             * 5) Total offcuts used less than total available
             */
            $availableOffcuts = $business->availableOffcuts()
                ->matchProduct($product)
                ->get();

            $sumOffcutFullLength = 0;
            foreach($utilisedOffcutBars as $utilisedOffcutBar){
                $sumOffcutFullLength = $sumOffcutFullLength + $utilisedOffcutBar["sourceOffcut"]["offcutLength"];
            }
            if($sumOffcutFullLength <= $availableOffcuts->sum("length")){
                $countQ5++;

            }
            $numberQ5 = $numberQ5 + $availableOffcuts->sum("length");

            /*
             * 6) New offcuts + scrap = total unused
             */
            $oldStock = $product->nested["totals"]["oldStock"];
            $newStock = $product->nested["totals"]["newStock"];
            $totalScrap = $oldStock["scrap"] +$newStock["scrap"];
            $totalUnused = $oldStock["unused"] +$newStock["unused"];
            $newOffcuts = $newStock["reusable"];

            if(($totalScrap + $newOffcuts) === $totalUnused){
                $countQ6++;
            }
            $numberQ6 = $numberQ6 + $totalUnused;

            /*
             * 7) Each bar: sum of cuts less than bar
             */
            $countSumCutsLessThanLength = 0;
            foreach($utilisedOffcutBars as $utilisedOffcutBar){
                if($utilisedOffcutBar["sourceOffcut"]["cutLength"] < $utilisedOffcutBar["sourceOffcut"]["offcutLength"]){
                    $countSumCutsLessThanLength++;
                }
            }
            if($countSumCutsLessThanLength === count($utilisedOffcutBars)){
                $countQ7++;
            }

            /*
             * 8) Total cuts + total new offcuts + scrap = total bought + used offcuts
             */
            $totalUsed = $oldStock["used"] +$newStock["used"];
            $orderList = $product->nested["orderList"];
            $sumOrderLength = 0;
            foreach($orderList as $item){
                $sumOrderLength = $sumOrderLength + ($item["count"] * $item["result"]);
            }

            if(($totalUsed + $newOffcuts + $totalScrap) === ($sumOrderLength + $oldStock["total"])){
                $countQ8++;
            }

            /*
             * 9) Using more old stock than scraping
             */
            if($oldStock["total"] > $totalScrap){
                $countQ9++;
            }
        }

        return [
            //1
            "total_length_input_output" => [
                "description" => "Total length of input pieces = total length of output cuts",
                "result" => count($meteragePieces) === $countQ1,
                "number" => $numberQ1,
                "suffix" => "mm",
            ],
            //2
            "total_qty_input_output" => [
                "description" => "Qty of input pieces = qty of output cuts",
                "result" => count($meteragePieces) === $countQ2,
                "number" => $numberQ2,
                "suffix" => null,
            ],
            //3
            "efficiency" => [
                "description" => "Efficiency 70%+",
                "result" => $efficiency,
                "number" => $efficiency_number,
                "suffix" => "%",
            ],
            //4
            "over_sized_cuts" => [
                "description" => "A cut longer than max stock length is categorised as 'too long'",
                "result" => count($meteragePieces) === $countQ4,
                "number" => null,
                "suffix" => null,
            ],
            //5
            "offcuts_qty" => [
                "description" => "Total offcuts used less than total available",
                "result" => count($meteragePieces) === $countQ5,
                "number" => $numberQ5,
                "suffix" => "mm",
            ],
            //6
            "unused_vs_offcuts" => [
                "description" => "New offcuts + scrap = total unused",
                "result" => count($meteragePieces) === $countQ6,
                "number" => $numberQ6,
                "suffix" => "mm",
            ],
            //7
            "cuts_within_bar" => [
                "description" => "Each bar: sum of cuts less than bar",
                "result" => count($meteragePieces) === $countQ7,
                "number" => null,
                "suffix" => null,
            ],
            //8
            "total_sums" => [
                "description" => "Total cuts + total new offcuts + scrap = total bought + used offcuts",
                "result" => count($meteragePieces) === $countQ8,
                "number" => null,
                "suffix" => null,
            ],
            //9
            "scrap_ratio" => [
                "description" => "Using more old stock than scraping",
                "result" => count($meteragePieces) === $countQ9,
                "number" => null,
                "suffix" => null,
            ],
        ];
    }

    /**
     * @param  array<int, string>  $preassigned  Letters already stamped on a saved nest, kept as they are
     */
    public function getLetterProjectArray(Collection $piecesReadyForBatching, array $preassigned = []): array
    {
        $projectIds = [];
        foreach ($piecesReadyForBatching as $piece) {
            $projectIds[] = $piece->project_id;
        }
        $projectIds = array_values(array_unique($projectIds));

        $lettersProjectArray = $preassigned;
        $taken = array_flip($preassigned);

        $index = 0;
        foreach ($projectIds as $id) {
            //Already stamped on the drawings, so it keeps the letter it has
            if (isset($lettersProjectArray[$id])) {
                continue;
            }

            do {
                $letter = $this->indexToLetter($index);
                $index++;
            } while (isset($taken[$letter]));

            $lettersProjectArray[$id] = $letter;
            $taken[$letter] = true;
        }

        return $lettersProjectArray;
    }

    private function indexToLetter(int $index): string
    {
        //A-Z, then AA, AB, ... so every project keeps a distinct mark on the cut drawings
        $letter = '';
        $remaining = $index;
        do {
            $letter = chr(65 + ($remaining % 26)).$letter;
            $remaining = intdiv($remaining, 26) - 1;
        } while ($remaining >= 0);

        return $letter;
    }

    /**
     * Recover the letters from a nest saved before they were stored alongside it.
     *
     * Every cut in the saved nesting carries the letter it was drawn with, so the map can be read back
     * off the nest itself rather than guessed at from the batch's projects.
     */
    public function lettersFromNestedState(array $piecesNested): array
    {
        $letters = [];

        foreach ($piecesNested[NestingEnums::METERAGE->value] ?? [] as $product) {
            $nested = ((array) $product)['nested'] ?? [];

            if (! is_array($nested)) {
                continue;
            }

            //Cuts made out of new stock
            foreach ($nested['utilisedBars'] ?? [] as $bar) {
                foreach ($bar['result']['pieces'] ?? [] as $cut) {
                    $letters[$cut['projectId']] = $cut['letter'];
                }
            }

            //Cuts made out of offcut inventory
            foreach ($nested['bestResultOffcuts']['utilisedOffcutBars'] ?? [] as $offcutBar) {
                foreach ($offcutBar['sourceOffcut']['cuts'] ?? [] as $cut) {
                    $letters[$cut['projectId']] = $cut['letter'];
                }
            }

            //Pieces no stock length could hold
            foreach ($nested['tooLong'] ?? [] as $cut) {
                $letters[$cut['project']] = $cut['letter'];
            }
        }

        return $letters;
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
            $query = Product::query()->active();
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

            $query = Product::query()->active();
            foreach ($pieceSpec as $field => $value) {
                $query->where($field, $value);
            }
            $allPacks = $query->get(['pack_size_1', 'pack_size_2', 'pack_size_3'])->toArray();

            //Every usable pack size across the matching products, not just the first row's
            $result = [];
            foreach ($allPacks as $packs) {
                foreach ($packs as $pack) {
                    $pack = (int) $pack;
                    if ($pack > 0) {
                        $result[] = $pack;
                    }
                }
            }
            $result = array_values(array_unique($result));
        }

        return $result;
    }

    public function meterageAlgorithm(
        array $cutLengthsRequired,
        array $purchasableStockLengths,
        array $offcutInventory,
        array $lettersProjectArray,
        Business $business,
        ?object $newPieceSpec = null,
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
         *  2F) Iterate 100 times and choose the highest efficiency result
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
                foreach($offcutBar["sourceOffcut"]["cuts"] as $cut){
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

        /*
         * Deterministic randomness.
         *
         * The same inputs must always produce the same nesting, otherwise the plan the user approves on
         * the "suggested nesting" screen is not the plan saved against the batch when they start quoting.
         */
        $randomizer = $this->seededRandomizer(
            $cutLengthsRequiredAfterOffcutAllocation,
            $purchasableStockLengths,
            $business,
        );

        /*
         * Keep the best run seen so far.
         *
         * Note efficiency is a float, so it cannot be used as an array key - PHP would truncate it to an
         * int, collapsing (say) 96.9% and 96.1% onto the same key and discarding the better of the two.
         */
        $bestEfficiency = null;
        $bestResult = null;

        //100+ random selection
        for ($i = 1; $i <= (int) config('env.nesting_iterations'); $i++) {
            $singleRun = $this->singleRun(
                $cutLengthsRequiredAfterOffcutAllocation,
                $lettersProjectArray,
                $purchasableStockLengths,
                $business,
                true,
                $newPieceSpec,
                $randomizer,
            );

            if ($bestEfficiency === null || $singleRun["efficiency"] > $bestEfficiency) {
                $bestEfficiency = $singleRun["efficiency"];
                $bestResult = $singleRun["result"];
            }
        }

        //"Best fit" comparison
        $singleRun = $this->singleRun(
            $cutLengthsRequiredAfterOffcutAllocation,
            $lettersProjectArray,
            $purchasableStockLengths,
            $business,
            false,
            $newPieceSpec,
            $randomizer,
        );

        if ($bestEfficiency === null || $singleRun["efficiency"] > $bestEfficiency) {
            $bestEfficiency = $singleRun["efficiency"];
            $bestResult = $singleRun["result"];
        }

        //2F) Iterate 100 times and choose the highest efficiency result
        $utilisedBars = $bestResult["utilisedBars"];
        $tooLong = $bestResult["tooLong"];
        $sums = $bestResult["sums"];

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
        foreach($nestRequiredCutsIntoOffcuts as $originalOffcut){
            $offcutLength = $originalOffcut["length"];
            $usedLength = $offcutLength - $originalOffcut["unused"];

            $utilisedOffcutBars[] = [
                "sourceOffcut" => [
                    "offcutLength" => $originalOffcut["length"],
                    "cutLength" => $usedLength,
                    "offcutId" => $originalOffcut["offcut_id"],
                    "unique_mark" => $originalOffcut["unique_mark"],
                    "batchFromId" => $originalOffcut["batch_from_id"],
                    "scrap_threshold_mm" => $business->scrap_threshold_mm,
                    "cuts" => $originalOffcut["cuts"], //length, projectId, piece_id, letter
                ],
                //">=" to match the per-bar test in Actions/Bar/CreateBarsAndOffcuts, which decides
                //which drops actually become offcut records
                "offcutFromOffcut" => [
                    "reusableLength" => (($offcutLength - $usedLength) >= $business->scrap_threshold_mm) ? ($offcutLength - $usedLength) : 0,
                ],
                "scrap" => [
                    "scrapLength" => (($offcutLength - $usedLength) >= $business->scrap_threshold_mm) ? 0 : ($offcutLength - $usedLength),
                ],
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
            $totalOffcutsLength = $totalOffcutsLength + $utilisedOffcutBar["sourceOffcut"]["offcutLength"];
            $totalUsedOffcuts = $totalUsedOffcuts + $utilisedOffcutBar["sourceOffcut"]["cutLength"];
            $totalScrapLength = $totalScrapLength + $utilisedOffcutBar["scrap"]["scrapLength"];
            $totalReusableLength = $totalReusableLength + $utilisedOffcutBar["offcutFromOffcut"]["reusableLength"];
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
                            "unique_mark" => $offcutData["unique_mark"],
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
        Object|null $newPieceSpec,
        Randomizer $randomizer,
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
                        //Random length (seeded, so the same inputs always nest the same way)
                        $randomKey = $randomizer->getInt(0, count($purchasableStockLengthsLongEnough) - 1);
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
                        $newPieceSpec,
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
        $totalReusable = 0;
        $totalScrap = 0;
        foreach($utilisedBars as $utilisedBar){
            $totalPurchasedMaterial = $totalPurchasedMaterial + $utilisedBar["bar_length"];
            $totalUnused = $totalUnused + $utilisedBar["unused"];

            /*
             * Reusable vs scrap is a property of the individual drop, not of the total.
             * Ten bars each with 400mm left over is ten pieces of scrap, not 4m of reusable stock.
             * This has to match the per-bar test in Actions/Bar/CreateBarsAndOffcuts, which decides
             * which drops actually become offcut records.
             */
            if($utilisedBar["unused"] >= $business->scrap_threshold_mm){
                $totalReusable = $totalReusable + $utilisedBar["unused"];
            }
            else{
                $totalScrap = $totalScrap + $utilisedBar["unused"];
            }
        }
        $totalUsedMaterial = $totalPurchasedMaterial - $totalUnused;
        $efficiency = $totalPurchasedMaterial > 0
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
                    "totalReusable" => $totalReusable,
                    "totalScrap" => $totalScrap,
                ],
            ]
        ];
    }

//    private function generateUniqueCode(&$usedCodes): string
//    {
//        do {
//            $code = '';
//            for ($i = 0; $i < 4; $i++) {
//                $code .= chr(rand(65, 90)); // Generate random uppercase letter (A-Z)
//            }
//        } while (in_array($code, $usedCodes)); // Ensure uniqueness
//
//        $usedCodes[] = $code; // Store used code
//        return $code;
//    }

    private function seededRandomizer(
        array $cutLengthsRequired,
        array $purchasableStockLengths,
        Business $business,
    ): Randomizer
    {
        /**
         * Seed the randomiser from the inputs to the nest.
         *
         * Nesting is run twice for the same pieces: once to show the user a suggestion, and again by
         * Actions/Batch/SaveNesting when they press "start quoting". An unseeded randomiser makes those
         * two runs disagree, so the batch is ordered against a cut plan nobody looked at.
         */
        $lengths = array_map(fn ($cut) => (int) $cut['length'], $cutLengthsRequired);
        sort($lengths);

        $stockLengths = array_map('intval', $purchasableStockLengths);
        sort($stockLengths);

        $seed = crc32(implode(',', $lengths).'|'.implode(',', $stockLengths).'|'.$business->id);

        return new Randomizer(new Mt19937($seed));
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
        Business $business,
        object|null $newPieceSpec,
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
            "offcut_id" => null //This gets carried over via serialisation, but before offcut ID is created
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

        /*
         * Drop anything that isn't a usable pack size BEFORE sorting, and re-index.
         * array_filter preserves keys, so filtering afterwards leaves holes and "the last key" is
         * then no longer the smallest box - or even a key that exists.
         */
        $boxSizes = array_values(array_filter(
            array_map('intval', $boxSizes),
            fn ($value) => $value > 0,
        ));

        // Sort the box sizes in descending order
        rsort($boxSizes);

        //Nothing purchasable
        if (count($boxSizes) === 0) {
            return [
                'totalBought' => 0,
                'efficiency' => 0,
                'boxes' => [],
            ];
        }

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
            'totalBought' => $totalBought,
            'efficiency' => $totalBought > 0
                ? ($originalQty / $totalBought * 100)
                : 0,
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
        //Only the stock length matters here, so bars consolidate regardless of project refs
        $newResult = [];
        foreach ($utilisedBars as $bar) {
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
        $checks = null;

        //Batch (after batch object exists)
        if ($type === 'BATCH') {
            //Projects in batch
            $projectsForBatching = $batch->projects();

            //Pieces nested (from saved)
            //todo refactor this to collecting 'BAR' and 'OFFCUT' items
            /*
             * batch > offcut
             * offcut > bar
             */
            /*
             * nested_state is nullable and only written by Actions/Batch/SaveNesting, so a batch can
             * legitimately have none. The NestedState cast decodes it, and gives back an empty nest
             * rather than something the callers below cannot read.
             */
            $piecesNested = $batch->nested_state;

            /*
             * Letter-project array.
             *
             * The letters are fixed when the nest is saved and stamped onto every cut in it, so the
             * legend has to use that same map. Recomputing it here ordered the projects differently,
             * and the legend could then name a different project than the drawings marked.
             */
            $lettersProjectArray = $this->getLetterProjectArray(
                $batch->pieces,
                $batch->letters_project_array ?: $this->lettersFromNestedState($piecesNested),
            );

            ////////////////////////////////////////////////////////////////////////////

            //Nesting stats
            $usageStats = $this->usageStats($piecesNested);

            //Pieces grouped by supplier group
            $piecesGroupedBySupplierGroup = $this->piecesGroupedBySupplierGroup($piecesNested, $business);
        }
        //Suggested (pre-batch at nesting phase)
        if ($type === 'SUGGESTED') {
            //Pieces ready for batching
            $piecesReadyForBatching = $this->piecesReadyForBatching($business);

            //Projects ready for batching
            $projectsForBatching = $business->projectsReadyForBatching($piecesReadyForBatching);

            //Letter-project array
            $lettersProjectArray = $this->getLetterProjectArray($piecesReadyForBatching);

            //Pieces nested (generated)
            $piecesNested = $this->piecesNested($piecesReadyForBatching, $lettersProjectArray, $business);

            //Nesting stats
            $usageStats = $this->usageStats($piecesNested);

            //Checks
            $checks = $this->checks($piecesNested,$usageStats,$business);

            //Pieces grouped by supplier group
            $piecesGroupedBySupplierGroup = $this->piecesGroupedBySupplierGroup($piecesNested, $business);
        }

        return [
            'pieces' => $piecesNested,
            'projectsReadyForBatching' => ProjectResource::collection($projectsForBatching),
            'piecesGroupedBySupplierGroup' => $piecesGroupedBySupplierGroup,
            'usage' => $usageStats,
            "checks" => $checks,
            'type' => $type,
            "lettersProjectArray" => $lettersProjectArray,
        ];
    }

    /**
     * Collections
     */
    public function piecesReadyForBatching(Business $business): Collection
    {
        return Piece::query()
            //Every nesting algo reads $piece->project, once per piece and once per cut
            ->with("project")
            ->whereRelation("project.user.business","id","=",$business->id)
            ->whereRelation("project","archive","=",false)
            ->doesntHave("batch")
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
        $pieces = $pieces->sortBy('actual_length');

        $piecesArray = [];
        foreach ($pieces as $piece) {
            $piecesArray[] = [
                'project' => $piece->project,
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
        $offcutInventory = $business->availableOffcuts()
            ->matchProduct($newPieceSpec)
            ->get()
            ->toArray();

        $newPieceSpec->offcutInventoryLengths = $offcutInventory;

        /*
         * Nesting
         */
        $cutLengthsRequired = [];
        foreach ($pieces as $piece) {
            $projectId = $piece->project->id;

            for ($i = 0; $i < (int) $piece->actual_qty; $i++) {
                $cutLengthsRequired[] = [
                    'project' => $projectId,
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
                $pieces = $pieces->sortBy('product_category'); //todo something more useful

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
                /*
                 * Collection::where() returns a new collection - it does not filter in place.
                 * Reassigning is what keeps each spec's quantity to its own pieces; without it every
                 * spec was nested against every bundle piece and the order quantity multiplied.
                 */
                $pieces = $allPieces;
                foreach ($uniquePieceSpec as $field => $value) {
                    $pieces = $pieces->where($field, $value);
                }
                $pieces = $pieces->sortBy('product_category'); //todo something more useful

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
                        'project' => $piece->project,
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
