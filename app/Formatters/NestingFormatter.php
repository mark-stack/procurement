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
use App\Models\User;
use App\Services\ProductService;
use Illuminate\Database\Eloquent\Builder;
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
                'totalOffcutMaterial' => 0,
                'totalUsedMaterial' => 0,
                "totalReusable" => 0,
                "totalKerf" => 0,
                "totalScrap" => 0,
                'efficiency' => 0,
                'yield' => 0,
            ],
            NestingEnums::BUNDLE->value => [
                'totalRequired' => 0,
                'totalBought' => 0,
                'totalSurplus' => 0,
                'efficiency' => 0,
            ],
        ];

        //Loop different supplier groups. e.g "steel merchant"
        foreach ($piecesNested as $algo => $items) {
            //Meterage
            if($algo === NestingEnums::METERAGE->value){
                $totalPurchasedMaterial = 0;
                $totalOffcutMaterial = 0;
                $totalUsedMaterial = 0;
                $totalReusable = 0;
                $totalKerf = 0;
                $totalScrap = 0;

                //Loop different products. e.g PFC
                foreach ($items as $item) {
                    $item = (array) $item;

                    if (isset($item['nested']['totals'])) {
                        $totals = $item['nested']['totals'];

                        /*
                         * Offcuts are kept apart from purchases. Adding them together meant drawing on
                         * stock already in the yard inflated "purchased" and sank the efficiency figure,
                         * so a business that reused its own steel scored worse than one that bought new.
                         */
                        $totalPurchasedMaterial = $totalPurchasedMaterial + $totals["newStock"]['total'];
                        $totalOffcutMaterial = $totalOffcutMaterial + $totals["oldStock"]['total'];
                        $totalUsedMaterial = $totalUsedMaterial + $totals["oldStock"]['used'] + $totals["newStock"]['used'];
                        $totalReusable = $totalReusable + $totals["oldStock"]['reusable'] + $totals["newStock"]['reusable'];
                        $totalKerf = $totalKerf + ($totals["oldStock"]['kerf'] ?? 0) + ($totals["newStock"]['kerf'] ?? 0);
                        $totalScrap = $totalScrap + $totals["oldStock"]['scrap'] + $totals["newStock"]['scrap'];
                    }
                }

                $totalMaterial = $totalPurchasedMaterial + $totalOffcutMaterial;

                $result[$algo] = [
                    'totalPurchasedMaterial' => $totalPurchasedMaterial,
                    'totalOffcutMaterial' => $totalOffcutMaterial,
                    'totalUsedMaterial' => $totalUsedMaterial,
                    "totalReusable" => $totalReusable,
                    "totalKerf" => $totalKerf,
                    "totalScrap" => $totalScrap,
                    /*
                     * Efficiency: the share of everything handled that was not destroyed. A drop at or
                     * over the scrap threshold is banked as an offcut, so it is not waste - only scrap
                     * and saw kerf are.
                     */
                    'efficiency' => $totalMaterial === 0
                        ? 0
                        : (round((($totalMaterial - $totalScrap - $totalKerf) / $totalMaterial * 100),1)),
                    //Yield: the share that left as finished pieces. Lower, and the two answer different questions
                    'yield' => $totalMaterial === 0
                        ? 0
                        : (round(($totalUsedMaterial / $totalMaterial * 100),1)),
                ];
            }

            //Bundle
            if($algo === NestingEnums::BUNDLE->value){
                $totalRequired = 0;
                $totalBought = 0;

                foreach ($items as $item) {
                    $item = (array) $item;

                    foreach ($item['pieces'] ?? [] as $piece) {
                        $totalRequired = $totalRequired + (int) $piece['quantity'];
                    }

                    $totalBought = $totalBought + (int) ($item['nested']['totalBought'] ?? 0);
                }

                $result[$algo] = [
                    'totalRequired' => $totalRequired,
                    'totalBought' => $totalBought,
                    'totalSurplus' => $totalBought - $totalRequired,
                    'efficiency' => $totalBought === 0
                        ? 0
                        : (round(($totalRequired / $totalBought * 100),1)),
                ];
            }

            //Area
            //todo 2D nesting is not implemented, so there is nothing to total up yet
        }

        return $result;
    }

    public function checks(array $piecesNested, array $usageStats, Business $business): array
    {
        /**
         1) Total length of input pieces = total length of output cuts
         2) Qty of input pieces = qty of output cuts
         3) Efficiency (share of material not scrapped) 70%+
         4) a cut longer than max stock length is categorised as "too long"
         5) total offcuts used < total available
         6) new offcuts + scrap = total unused
         7) Each bar and offcut: cuts plus kerf fit inside it
         8) total cuts + new offcuts + scrap + kerf = total bought + offcuts used
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
            /*
             * Sum pieces, on the same whole-millimetre basis the cuts were made on.
             *
             * A piece length is a string fed from a float, so "1432.5" is real. Summing the raw values
             * made this a float comparison against an integer total, and "===" is never true between
             * the two - so this check reported a failure on every nest that had a fractional length in it.
             */
            $sumPieces = 0;
            foreach($product->pieces as $piece){
                $sumPieces = $sumPieces + ($this->cutLength($piece["length"]) * (int) $piece["quantity"]);
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
                $sumTooLong = $sumTooLong + $this->cutLength($tooLong["length"]);
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
                $qtyPieces = $qtyPieces + (int) $piece["quantity"];
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
                //A cut also does not fit if the bar cannot hold it AND the blade pass it takes
                $kerf = $this->kerf($business);
                foreach($tooLong as $item){
                    $length = $this->cutLength($item["length"]);
                    if(count($product->purchasableLengths) === 0
                        || ($length + $kerf) > max($product->purchasableLengths)){
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
            $totalKerf = ($oldStock["kerf"] ?? 0) + ($newStock["kerf"] ?? 0);
            /*
             * Every reusable drop, not just the ones cut from new stock. Counting only newStock made
             * this check fail whenever an offcut left a remainder big enough to bank - the one case the
             * offcut nesting is there to produce.
             */
            $newOffcuts = $oldStock["reusable"] + $newStock["reusable"];

            if(($totalScrap + $newOffcuts) === $totalUnused){
                $countQ6++;
            }
            $numberQ6 = $numberQ6 + $totalUnused;

            /*
             * 7) Each bar: sum of cuts fits inside the bar
             *
             * This only ever looked at the offcut bars, so the new stock bars - the ones the algorithm
             * actually builds - went unchecked. It also demanded the cuts come to strictly less than the
             * bar, which failed a bar consumed exactly.
             */
            $barsChecked = 0;
            $barsWithinLength = 0;

            foreach($utilisedOffcutBars as $utilisedOffcutBar){
                $barsChecked++;
                $consumed = $utilisedOffcutBar["sourceOffcut"]["cutLength"]
                    + ($utilisedOffcutBar["sourceOffcut"]["kerfLength"] ?? 0);

                if($consumed <= $utilisedOffcutBar["sourceOffcut"]["offcutLength"]){
                    $barsWithinLength++;
                }
            }

            foreach($product->nested["utilisedBars"] as $utilisedBar){
                $barsChecked++;
                $consumed = ($utilisedBar["result"]["kerf"] ?? 0);
                foreach($utilisedBar["result"]["pieces"] as $cut){
                    $consumed = $consumed + $cut["cutLength"];
                }

                if($consumed <= $utilisedBar["result"]["bar_length"]){
                    $barsWithinLength++;
                }
            }

            if($barsWithinLength === $barsChecked){
                $countQ7++;
            }

            /*
             * 8) Total cuts + total new offcuts + scrap + kerf = total bought + used offcuts
             */
            $totalUsed = $oldStock["used"] +$newStock["used"];
            $orderList = $product->nested["orderList"];
            $sumOrderLength = 0;
            foreach($orderList as $item){
                $sumOrderLength = $sumOrderLength + ($item["count"] * $item["result"]);
            }

            if(($totalUsed + $newOffcuts + $totalScrap + $totalKerf) === ($sumOrderLength + $oldStock["total"])){
                $countQ8++;
            }

            /*
             * 9) Using more old stock than scraping
             *
             * ">=" so a nest with no offcut inventory to draw on and nothing scrapped passes. With ">"
             * it failed on 0 against 0, which reads as a problem with a nest that has none.
             */
            if($oldStock["total"] >= $totalScrap){
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
                "description" => "Efficiency (material not scrapped) 70%+",
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
                "description" => "Each bar and offcut: cuts plus kerf fit inside it",
                "result" => count($meteragePieces) === $countQ7,
                "number" => null,
                "suffix" => null,
            ],
            //8
            "total_sums" => [
                "description" => "Total cuts + new offcuts + scrap + kerf = total bought + offcuts used",
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
            /*
             * Scoped to this business. active() alone only excludes deprecated products, so a nest
             * could be built on stock lengths from another business's private products.
             */
            $query = Product::query()->availableForBusiness($business);
            foreach ($pieceSpec as $field => $value) {
                $query = $this->wherePieceSpecField($query, $field, $value);
            }

            /*
             * 12m length cap
             */
            $result = $query
                ->pluck('nominal_length')
                ->unique()
                ->toArray();

            //Convert all string numbers to integers
            $result = array_map('intval', $result);

            //12m stock limit (for ease of delivery)
            if($business->cap_12m_stock){
                $result = array_filter(
                    $result,
                    fn($num) => $num <= 12000
                );
            }

            //Re-index: array_filter keeps the keys, and a holed array serialises to JSON as an object
            sort($result);
        }
        //AREA = nominal_length & nominal_width
        if ($algo === NestingEnums::AREA->value) {
            //todo 2D no area nesting yet

        }
        //BUNDLE = pack size
        if ($algo === NestingEnums::BUNDLE->value) {

            $query = Product::query()->availableForBusiness($business);
            foreach ($pieceSpec as $field => $value) {
                $query = $this->wherePieceSpecField($query, $field, $value);
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

    /**
     * Match one field of a piece spec against the products table.
     *
     * A spec field can legitimately be null - not every product category uses every mandatory field.
     * "where field = null" is never true in SQL, so a spec with one null field matched no product at
     * all and the nest was left with no purchasable lengths to work from.
     */
    private function wherePieceSpecField(Builder $query, string $field, mixed $value): Builder
    {
        return $value === null
            ? $query->whereNull($field)
            : $query->where($field, $value);
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
         * STEP 1: use & optimise offcuts
         *  1A) Sort the required cuts descending, so the big ones get first claim on the inventory
         *  1B) For each cut, prefer an already-opened offcut, tightest remaining space first
         *  1C) Otherwise open the offcut that leaves the least SCRAP behind - a drop at or over the
         *      scrap threshold goes back into inventory and costs nothing, one below it is destroyed
         *
         * STEP 2: use & optimise new stock
         *  "The Least Bins packing problem", searched over both the stock length and the packing
         *  2A) Remove pieces that have been allocated to offcuts
         *  2B) Sort the cut lengths in descending order to prioritize fitting large pieces first.
         *  2C) Start with an empty list of bins
         *  2D) Fitting into bins: "Best-Fit Decreasing" = place each item into the bin it leaves the
         *      least space in. Random runs pick among the bins that fit instead.
         *  2E) If no existing bin can accommodate it, create a new bin (smallest that fits, or a random
         *      one on a random run)
         *  2F) Iterate and keep the run that destroys the least material for the least purchase
         */

        /**
         * STEP 1
         */
        //Nesting required cuts into offcut inventory. e.g might cut 3x 900mm from 3,000mm
        $bestResultOffcuts = $this->bestResultOffcuts($cutLengthsRequired,$offcutInventory,$lettersProjectArray, $business);

        /**
         * STEP 2
         */
        /*
         * 2A) Remove pieces from "cutLengthsRequired" that have been allocated to offcuts
         *
         * Matched on piece_id as well as length. Matching on length alone removed "the first cut of
         * that length", which is only the right one while the descending sort stays stable and this
         * scan stays in input order - and it left the offcut drawing crediting another project.
         */
        $cutLengthsRequiredAfterOffcutAllocation = $cutLengthsRequired;
        foreach($bestResultOffcuts["utilisedOffcutBars"] as $offcutBar){
            //Loop cuts
            foreach($offcutBar["sourceOffcut"]["cuts"] as $cut){
                foreach($cutLengthsRequiredAfterOffcutAllocation as $index => $requiredLength){
                    $sameLength = $this->cutLength($requiredLength['length']) === $cut["length"];
                    $samePiece = $requiredLength["piece_id"] === $cut["piece_id"];

                    if($sameLength && $samePiece){
                        unset($cutLengthsRequiredAfterOffcutAllocation[$index]);
                        break;
                    }
                }
            }
        }
        $cutLengthsRequiredAfterOffcutAllocation = array_values($cutLengthsRequiredAfterOffcutAllocation);

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
         * Keep the best run seen so far, compared by value.
         *
         * Runs used to be collected into an array keyed by their efficiency. Efficiency is a float and
         * PHP truncates float array keys to int, so 96.9% and 96.1% collided on key 96 and the better
         * run was overwritten by whichever ran last.
         *
         * The deterministic run goes first, so it is what an unbeaten search falls back to.
         *
         * The random runs then vary BOTH the stock length and the packing. Randomising the stock length
         * alone meant every iteration packed the cuts identically, so a product with one purchasable
         * length nested the same way a thousand times over and the iteration count bought nothing.
         */
        $singleRun = $this->singleRun(
            $cutLengthsRequiredAfterOffcutAllocation,
            $lettersProjectArray,
            $purchasableStockLengths,
            $business,
            false,
            $newPieceSpec,
            $randomizer,
        );
        $bestScore = $this->runScore($singleRun["result"]["sums"]);
        $bestResult = $singleRun["result"];

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

            $score = $this->runScore($singleRun["result"]["sums"]);

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestResult = $singleRun["result"];
            }
        }

        //2F) Keep the run that destroys the least material for the least purchase
        $utilisedBars = $bestResult["utilisedBars"];
        $tooLong = $bestResult["tooLong"];
        $sums = $bestResult["sums"];

        /**
         * Consolidate utilised stock bars that are the same (same length and cuts array)
         */
        $orderList = $this->orderList($utilisedBars);
        $utilisedBars = $this->consolidateStockNestingResults($utilisedBars);

        $totals = [
            "oldStock" => [
                "total" => $bestResultOffcuts["totalOffcutsLength"],
                "used" => $bestResultOffcuts["totalUsedOffcuts"],
                "unused" => $bestResultOffcuts["totalUnusedOffcuts"],
                "kerf" => $bestResultOffcuts["totalKerfLength"],
                "reusable" => $bestResultOffcuts["totalReusableLength"],
                "scrap" => $bestResultOffcuts["totalScrapLength"],
            ],
            "newStock" => [
                "total" => $sums["totalPurchasedMaterial"],
                "used" => $sums["totalUsedMaterial"],
                "unused" => $sums["totalUnused"],
                "kerf" => $sums["totalKerf"],
                "reusable" => $sums["totalReusable"],
                "scrap" => $sums["totalScrap"],
            ],
        ];

        return [
            'utilisedBars' => $utilisedBars,
            "bestResultOffcuts" => $bestResultOffcuts,
            'tooLong' => $tooLong,
            'orderList' => $orderList,
            'totals' => $totals,
            /*
             * "Effective efficiency": the share of every millimetre handled that was NOT destroyed.
             *
             * A drop at or over the scrap threshold goes back into inventory, so it is not waste - only
             * scrap and saw kerf are. Judging a nest on used/total instead made drawing on offcuts you
             * already own look like a worse nest than buying new steel, because the whole offcut went
             * into the denominator while the reusable remainder of it counted for nothing.
             */
            'effectiveEfficiency' => $this->effectiveEfficiency($totals),
        ];
    }

    /**
     * The share of all material handled that was not destroyed. See meterageAlgorithm().
     *
     * @param  array{oldStock: array<string, int>, newStock: array<string, int>}  $totals
     */
    public function effectiveEfficiency(array $totals): float
    {
        $total = $totals["oldStock"]["total"] + $totals["newStock"]["total"];

        if ($total <= 0) {
            return 0;
        }

        $destroyed = $totals["oldStock"]["scrap"] + $totals["newStock"]["scrap"]
            + $totals["oldStock"]["kerf"] + $totals["newStock"]["kerf"];

        return round((($total - $destroyed) / $total * 100), 1);
    }

    /**
     * Rank one packing run against another. Compared as a list, so earlier entries decide first.
     *
     * Ranking on used/purchased alone could not tell apart two runs that bought the same bars, so a
     * pack leaving 400mm of scrap scored the same as one leaving a 2,000mm offcut.
     *
     * @param  array<string, int>  $sums
     * @return array<int, int>
     */
    private function runScore(array $sums): array
    {
        /*
         * Purchase comes first because it is the only line the business actually pays. Ranking scrap
         * above it buys a whole extra bar to save a few hundred millimetres of offcut - and a reusable
         * drop is deferred value, not free steel. Scrap then settles which of the equally-priced plans
         * to take, which is the comparison used/purchased could not make at all.
         */
        //Negated, so "greater is better" holds throughout
        return [
            //1) Buy as little steel as possible
            -$sums["totalPurchasedMaterial"],
            //2) Destroy as little of it as possible - scrap and saw kerf are gone for good, a drop at
            //   or over the scrap threshold goes back into inventory
            -($sums["totalScrap"] + $sums["totalKerf"]),
            //3) Out of fewer bars, which is less handling for the same steel
            -$sums["totalBars"],
            //4) With what is left over in as few pieces as possible - one 2,500mm drop is a more
            //   useful offcut than a 1,000mm and a 1,500mm, though the totals are identical
            $sums["largestReusable"],
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
            $business,
        );

        $utilisedOffcutBars = [];
        foreach($nestRequiredCutsIntoOffcuts as $originalOffcut){
            $offcutLength = $originalOffcut["length"];
            $drop = $originalOffcut["unused"];

            //Material that became pieces. The saw kerf is neither this nor the drop - it is swarf
            $usedLength = 0;
            foreach($originalOffcut["cuts"] as $cut){
                $usedLength = $usedLength + $cut["length"];
            }

            $utilisedOffcutBars[] = [
                "sourceOffcut" => [
                    "offcutLength" => $offcutLength,
                    "cutLength" => $usedLength,
                    "kerfLength" => $originalOffcut["kerf"],
                    "offcutId" => $originalOffcut["offcut_id"],
                    "unique_mark" => $originalOffcut["unique_mark"],
                    "batchFromId" => $originalOffcut["batch_from_id"],
                    "scrap_threshold_mm" => $business->scrap_threshold_mm,
                    "cuts" => $originalOffcut["cuts"], //length, projectId, piece_id, letter
                ],
                //">=" to match the per-bar test in Actions/Bar/CreateBarsAndOffcuts, which decides
                //which drops actually become offcut records
                "offcutFromOffcut" => [
                    "reusableLength" => ($drop >= $business->scrap_threshold_mm) ? $drop : 0,
                ],
                "scrap" => [
                    "scrapLength" => ($drop >= $business->scrap_threshold_mm) ? 0 : $drop,
                ],
            ];
        }

        /*
         * Totals.
         *
         * Every offcut balances: offcutLength = cuts + kerf + reusable + scrap.
         */
        $totalOffcutsLength = 0;
        $totalScrapLength = 0;
        $totalUsedOffcuts = 0;
        $totalReusableLength = 0;
        $totalKerfLength = 0;
        foreach($utilisedOffcutBars as $utilisedOffcutBar){
            $totalOffcutsLength = $totalOffcutsLength + $utilisedOffcutBar["sourceOffcut"]["offcutLength"];
            $totalUsedOffcuts = $totalUsedOffcuts + $utilisedOffcutBar["sourceOffcut"]["cutLength"];
            $totalKerfLength = $totalKerfLength + $utilisedOffcutBar["sourceOffcut"]["kerfLength"];
            $totalScrapLength = $totalScrapLength + $utilisedOffcutBar["scrap"]["scrapLength"];
            $totalReusableLength = $totalReusableLength + $utilisedOffcutBar["offcutFromOffcut"]["reusableLength"];
        }
        $totalUnusedOffcuts = $totalReusableLength + $totalScrapLength;

        return [
            "utilisedOffcutBars" => $utilisedOffcutBars,
            "totalOffcutsLength" => $totalOffcutsLength,
            "totalUsedOffcuts" => $totalUsedOffcuts,
            "totalUnusedOffcuts" => $totalUnusedOffcuts,
            "totalKerfLength" => $totalKerfLength,
            "totalScrapLength" => $totalScrapLength,
            "totalReusableLength" => $totalReusableLength,
        ];
    }

    private function nestRequiredCutsIntoOffcuts(
        array $offcutInventory,
        array $cutLengthsRequired,
        array $lettersProjectArray,
        Business $business,
    ): array
    {
        /**
         * Take a list of required cuts and a list of available offcuts, and nest them. It includes cutting the offcut.
         */

        $utilisedOffcuts = [];

        $depletableOffcutInventory = $offcutInventory;
        $kerf = $this->kerf($business);

        foreach ($cutLengthsRequired as $cut) {
            $cutLength = $this->cutLength($cut['length']);
            $projectId = (int) $cut['project'];
            $pieceId = $cut["piece_id"];

            $cutData = [
                "length" => $cutLength,
                "projectId" => $projectId,
                "piece_id" => $pieceId,
                'letter' => $lettersProjectArray[$projectId],
            ];

            /*
             * Try an offcut already opened, the one it leaves the least room in.
             * Taking the first that fits could spend a 3,000mm remainder on a 200mm cut while a 250mm
             * remainder sat next to it.
             */
            $tightestIndex = null;
            foreach ($utilisedOffcuts as $index => $bar) {
                if ($bar['unused'] < $cutLength + $kerf) {
                    continue;
                }

                if ($tightestIndex === null || $bar['unused'] < $utilisedOffcuts[$tightestIndex]['unused']) {
                    $tightestIndex = $index;
                }
            }

            if ($tightestIndex !== null) {
                $utilisedOffcuts[$tightestIndex]['cuts'][] = $cutData;
                $utilisedOffcuts[$tightestIndex]['unused'] -= ($cutLength + $kerf);
                $utilisedOffcuts[$tightestIndex]['kerf'] += $kerf;

                continue;
            }

            /*
             * Otherwise open an offcut from inventory - the one that destroys the least material.
             *
             * A remainder at or over the scrap threshold goes back into inventory and costs nothing; one
             * below it is thrown away. Taking the first offcut that merely fits burned a 1,500mm offcut
             * on a 700mm cut and binned the 800mm left over, while a 12,000mm offcut alongside it would
             * have given 11,300mm straight back.
             */
            $selectedIndex = null;
            $selectedScore = null;
            foreach ($depletableOffcutInventory as $index => $offcutData) {
                $offcutLength = (int) $offcutData['length'];
                $drop = $offcutLength - $cutLength - $kerf;

                if ($drop < 0) {
                    continue;
                }

                $scrap = ($drop >= $business->scrap_threshold_mm) ? 0 : $drop;

                //Destroy as little as possible, and among equals take the shortest offcut
                $score = [$scrap, $offcutLength];

                if ($selectedScore === null || $score < $selectedScore) {
                    $selectedScore = $score;
                    $selectedIndex = $index;
                }
            }

            if ($selectedIndex !== null) {
                $offcutData = $depletableOffcutInventory[$selectedIndex];
                $offcutLength = (int) $offcutData['length'];

                $utilisedOffcuts[] = [
                    "length" => $offcutLength,
                    "offcut_id" => $offcutData["id"],
                    "unique_mark" => $offcutData["unique_mark"],
                    'unused' => $offcutLength - $cutLength - $kerf,
                    'kerf' => $kerf,
                    "batch_from_id" => $offcutData["batch_from_id"],
                    'cuts' => [$cutData],
                ];

                //Remove used offcut
                unset($depletableOffcutInventory[$selectedIndex]);
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
        $kerf = $this->kerf($business);

        // Process each cut length
        foreach ($cutLengthsRequiredAfterOffcutAllocation as $cut) {
            $cutLength = $this->cutLength($cut['length']);

            /*
             * 2D) Try to place the cut into a bar already opened.
             */
            $tryPlaceCutIntoUtilisedBars = $this->tryPlaceCutIntoUtilisedBars(
                $cut,
                $utilisedBars,
                $lettersProjectArray,
                $kerf,
                $random,
                $randomizer,
            );
            $utilisedBars = $tryPlaceCutIntoUtilisedBars["utilisedBars"];
            $placed = $tryPlaceCutIntoUtilisedBars["placed"];

            /*
             * 2E) If no existing bin can accommodate it, create a new bin.
             */
            if (! $placed) {
                $newStockPlaced = false;

                //Available stock lengths that can hold the cut and the blade pass it takes
                $purchasableStockLengthsLongEnough = [];
                foreach($purchasableStockLengths as $purchasableStockLength){
                    if ((int) $purchasableStockLength >= $cutLength + $kerf) {
                        $purchasableStockLengthsLongEnough[] = (int) $purchasableStockLength;
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
                     * Shortest bar that holds the cut.
                     *
                     * On its own this is a weak opening choice - the cuts arrive largest first, so the
                     * shortest bar that fits the current one leaves little room for the rest. It is the
                     * deterministic floor the random runs are measured against, not the answer.
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
        $totalKerf = 0;
        $totalUsedMaterial = 0;
        $largestReusable = 0;
        foreach($utilisedBars as $utilisedBar){
            $totalPurchasedMaterial = $totalPurchasedMaterial + $utilisedBar["bar_length"];
            $totalUnused = $totalUnused + $utilisedBar["unused"];
            $totalKerf = $totalKerf + $utilisedBar["kerf"];

            foreach($utilisedBar["pieces"] as $piece){
                $totalUsedMaterial = $totalUsedMaterial + $piece["cutLength"];
            }

            /*
             * Reusable vs scrap is a property of the individual drop, not of the total.
             * Ten bars each with 400mm left over is ten pieces of scrap, not 4m of reusable stock.
             * This has to match the per-bar test in Actions/Bar/CreateBarsAndOffcuts, which decides
             * which drops actually become offcut records.
             */
            if($utilisedBar["unused"] >= $business->scrap_threshold_mm){
                $totalReusable = $totalReusable + $utilisedBar["unused"];
                $largestReusable = max($largestReusable, $utilisedBar["unused"]);
            }
            else{
                $totalScrap = $totalScrap + $utilisedBar["unused"];
            }
        }

        return [
            "result" => [
                "utilisedBars" => $utilisedBars,
                "tooLong" => $tooLong,
                //Every bar balances: bar_length = cuts + kerf + unused
                "sums" => [
                    "totalPurchasedMaterial" => $totalPurchasedMaterial,
                    "totalUsedMaterial" => $totalUsedMaterial,
                    "totalUnused" => $totalUnused,
                    "totalKerf" => $totalKerf,
                    "totalReusable" => $totalReusable,
                    "totalScrap" => $totalScrap,
                    //Used to rank runs, not reported
                    "totalBars" => count($utilisedBars),
                    "largestReusable" => $largestReusable,
                ],
            ]
        ];
    }

    /**
     * A required length in whole millimetres.
     *
     * Pieces store their length as a string fed from CsvService::normalisedLength(), which returns a
     * float - "1432.5" is real data. Truncating it with an int cast lost the half millimetre in the one
     * direction that matters, so a bar could be packed past its own length and every piece came up
     * short. Rounding up is the only safe direction for a cut list.
     */
    private function cutLength(string|int|float|null $length): int
    {
        return (int) ceil((float) $length);
    }

    /**
     * Material the blade turns into swarf on one cut. Zero unless the business has measured it.
     */
    private function kerf(Business $business): int
    {
        return max(0, (int) ($business->kerf_mm ?? 0));
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
        $lengths = array_map(fn ($cut) => $this->cutLength($cut['length']), $cutLengthsRequired);
        sort($lengths);

        $stockLengths = array_map('intval', $purchasableStockLengths);
        sort($stockLengths);

        $seed = crc32(implode(',', $lengths).'|'.implode(',', $stockLengths).'|'.$business->id);

        return new Randomizer(new Mt19937($seed));
    }

    private function sortCutLengthsDescending(array $cutLengthsRequired): array
    {
        /*
         * Longest first, then by piece and project, so the order is fully determined by the cuts
         * themselves. Sorting on length alone left cuts of equal length in whatever order the pieces
         * collection arrived in, and that order is not something the database promises.
         */
        usort($cutLengthsRequired, function ($a, $b) {
            return [$this->cutLength($b['length']), $a['piece_id'] ?? 0, $a['project']]
                <=> [$this->cutLength($a['length']), $b['piece_id'] ?? 0, $b['project']];
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
        $cutLength = $this->cutLength($cut['length']);
        $projectId = $cut['project'];
        $kerf = $this->kerf($business);

        $utilisedBars[] = [
            'bar_length' => $barLength,
            'unused' => $barLength - $cutLength - $kerf,
            'kerf' => $kerf,
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

    /**
     * Place one cut into a bar that is already open, if any of them can hold it.
     *
     * Deterministic runs take the bar the cut leaves the least room in ("Best-Fit Decreasing"). The
     * previous first-fit took whichever bar came first, which is what made every random iteration pack
     * the cuts identically - the only thing that varied was the stock length, so a product with one
     * purchasable length got the same nest a thousand times.
     */
    private function tryPlaceCutIntoUtilisedBars(
        array $cut,
        array $utilisedBars,
        array $lettersProjectArray,
        int $kerf,
        bool $random,
        Randomizer $randomizer,
    ): array
    {
        $cutLength = $this->cutLength($cut['length']);
        $projectId = (int) $cut['project'];

        //Every currently utilised stock bar the cut (and the blade pass it takes) fits into
        $candidates = [];
        foreach ($utilisedBars as $index => $stockBar) {
            if ($stockBar['unused'] >= $cutLength + $kerf) {
                $candidates[] = $index;
            }
        }

        if (count($candidates) === 0) {
            return [
                "utilisedBars" => $utilisedBars,
                "placed" => false,
            ];
        }

        if ($random) {
            /*
             * Opening a fresh bar counts as one more option, so the draw runs one past the candidates.
             *
             * Without it whole packings are unreachable: 4,300 + 4,300 + 3,700 + 3,700 into 9,000mm bars
             * can only ever be paired 4,300 + 4,300, because once the first bar is open the second
             * 4,300 is the only cut that fits it and nothing could choose otherwise. Pairing each 4,300
             * with a 3,700 buys the same two bars and scraps nothing.
             *
             * Seeded, so the same inputs always nest the same way.
             */
            $pick = $randomizer->getInt(0, count($candidates));

            if ($pick === count($candidates)) {
                return [
                    "utilisedBars" => $utilisedBars,
                    "placed" => false,
                ];
            }

            $selected = $candidates[$pick];
        }
        else {
            //Tightest fit: the bar this leaves the least room in
            $selected = $candidates[0];
            foreach ($candidates as $index) {
                if ($utilisedBars[$index]['unused'] < $utilisedBars[$selected]['unused']) {
                    $selected = $index;
                }
            }
        }

        //Add cut to 'pieces' of this stock bar
        $utilisedBars[$selected]['pieces'][] = [
            'cutLength' => $cutLength,
            'projectId' => $projectId,
            'letter' => $lettersProjectArray[$projectId],
        ];

        //Update 'unused' (remaining) and the kerf this bar has now given up
        $utilisedBars[$selected]['unused'] -= ($cutLength + $kerf);
        $utilisedBars[$selected]['kerf'] += $kerf;

        return [
            "utilisedBars" => $utilisedBars,
            "placed" => true,
        ];
    }

    public function bundleAlgorithm(int $totalQty, array $boxSizes): array
    {
        /**
         * Buy at least $totalQty, in whole packs, overshooting by as little as possible.
         *
         * Taking the biggest pack that fits and working down is not the same thing: 110 off with 100s
         * and 60s took a 100 and then a 60 for the last 10 (160 bought, 31% wasted) when two 60s cover
         * it for 120. Packs are few and small, so the exact answer is cheap - fill a table of the least
         * surplus for every quantity up to the first total a whole-pack combination can reach.
         *
         * Duplicate sizes are also removed. Keyed by size, a repeated pack overwrote its own count with
         * a later zero, and the caller was told to buy nothing at all.
         */
        $originalQty = $totalQty;

        /*
         * Drop anything that isn't a usable pack size, de-duplicate, and re-index.
         * array_filter preserves keys, so filtering without re-indexing leaves holes behind.
         */
        $boxSizes = array_values(array_unique(array_filter(
            array_map('intval', $boxSizes),
            fn ($value) => $value > 0,
        )));

        // Largest first, so ties in surplus are settled with fewer packs
        rsort($boxSizes);

        //Nothing purchasable, or nothing to buy
        if (count($boxSizes) === 0 || $totalQty <= 0) {
            return [
                'totalBought' => 0,
                'efficiency' => 0,
                'boxes' => [],
            ];
        }

        /*
         * Reaching exactly $totalQty may be impossible (7 off, packs of 5), so the search runs up to
         * the smallest pack past it - one of those is always reachable, and no cheaper answer lies
         * beyond it.
         */
        $ceiling = $totalQty + max($boxSizes);

        //best[$n] = fewest packs that come to exactly $n, and the pack that got there
        $best = array_fill(0, $ceiling + 1, null);
        $best[0] = ['packs' => 0, 'via' => null];

        for ($n = 1; $n <= $ceiling; $n++) {
            foreach ($boxSizes as $boxSize) {
                if ($boxSize > $n || $best[$n - $boxSize] === null) {
                    continue;
                }

                $packs = $best[$n - $boxSize]['packs'] + 1;

                if ($best[$n] === null || $packs < $best[$n]['packs']) {
                    $best[$n] = ['packs' => $packs, 'via' => $boxSize];
                }
            }
        }

        //The least we can buy that still covers the requirement
        $totalBought = null;
        for ($n = $totalQty; $n <= $ceiling; $n++) {
            if ($best[$n] !== null) {
                $totalBought = $n;
                break;
            }
        }

        //Walk the choices back into a pack mix
        $boxCounts = [];
        foreach ($boxSizes as $boxSize) {
            $boxCounts[$boxSize] = 0;
        }

        $remaining = $totalBought;
        while ($remaining > 0) {
            $boxSize = $best[$remaining]['via'];
            $boxCounts[$boxSize]++;
            $remaining -= $boxSize;
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

    public function getNestingGroups(User $user): array
    {
        /*
         * Scoped to what this user may actually see. Unscoped, these groups were built from
         * every row in the table - deprecated materials the import had retired, and other
         * businesses' private product categories.
         */
        $nestingGroups = [];

        foreach ([NestingEnums::METERAGE, NestingEnums::AREA, NestingEnums::BUNDLE] as $algo) {
            $products = Product::query()
                ->availableFor($user)
                ->where('nesting_algo', $algo->value)
                ->pluck('product_category')
                ->unique()
                ->toArray();

            $nestingGroups[$algo->value] = array_values($products);
        }

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
            /*
             * Ordered, because the nest and the project letters are both built by walking this list.
             * Unordered, the suggestion the user approved and the nest Actions/Batch/SaveNesting writes
             * could come out differently - the database does not promise a row order.
             */
            ->orderBy("id")
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

    /**
     * The distinct specs among a set of pieces, as [field => value] rows in $fieldLabels order.
     *
     * Read off the pieces already in hand. This used to go back to the database with a
     * select(...)->whereIn('id', ...)->distinct() per product category, to re-read rows that were
     * loaded a moment earlier - and it returned them in whatever order the database chose, which is
     * the order the whole nest is then built in.
     *
     * @param  array<int, string>  $fieldLabels
     * @return array<int, array<string, mixed>>
     */
    private function uniquePieceSpecs(Collection $pieces, array $fieldLabels): array
    {
        $specs = [];

        foreach ($pieces as $piece) {
            $spec = [];
            foreach ($fieldLabels as $field) {
                $spec[$field] = $piece->{$field};
            }

            //Keyed so the same spec collapses, however many pieces share it
            $specs[json_encode($spec)] = $spec;
        }

        ksort($specs);

        return array_values($specs);
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
            $uniquePieceSpecs = $this->uniquePieceSpecs($pieces, $fieldLabels);

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
            $uniquePieceSpecs = $this->uniquePieceSpecs($pieces, $fieldLabels);

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
            $uniquePieceSpecs = $this->uniquePieceSpecs($pieces, $fieldLabels);

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
