<?php

namespace App\Services;

use App\Enums\GradeEnums;
use App\Enums\MaterialEnums;
use App\Enums\MeasurementUnitEnums;
use App\Enums\NestingEnums;
use App\Enums\ProductEnums;
use App\Http\Resources\ProjectResource;
use App\Models\Batch;
use App\Models\Business;
use App\Models\Piece;
use App\Models\Product;
use App\Models\Project;
use Illuminate\Support\Collection;
use stdClass;

class NestingService
{
    public function allNestingAlgorithmLabels(): array
    {
        $result = [];

        $rawItems = Product::select("nesting_algo")
            ->active()
            ->distinct()
            ->get()
            ->toArray();

        foreach($rawItems as $rawItem){
            $result[] = $rawItem["nesting_algo"];
        }

        return $result;
    }

    public function allProductCategories(): array
    {
        $result = [];

        $rawItems = Product::select("product_category")
            ->active()
            ->distinct()
            ->get()
            ->toArray();

        foreach($rawItems as $rawItem){
            $result[] = $rawItem["product_category"];
        }

        return $result;
    }

    /**
     * @deprecated
     */
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
         * 'product_category', 'material', 'grade', 'surface', 'nominal_units', 'size'
         */
        $materialSpec = new stdClass();
        $materialSpec->product_category = $rawMaterialQuote->product_category;
        $materialSpec->material = $rawMaterialQuote->material;
        $materialSpec->grade = 999; //todo
        $materialSpec->surface = 999; //todo
        $materialSpec->nominal_units = $rawMaterialQuote->nominal_units;
        $materialSpec->size = 999; //todo

        /**
         * Length to compare to stock sizes
         */
        $lengthToCompare = (float) $rawMaterialQuote->length_required;



        $purchasableLengths = $this->getPurchasableVariations((array)$materialSpec);
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
            ->active()
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

        $rawItems = Product::select("product_category")
            ->active()
            ->distinct()
            ->where("certificates",true)
            ->get()
            ->toArray();

        foreach($rawItems as $rawItem){
            $result[] = $rawItem["product_category"];
        }

        return $result;
    }

    public function allGradeLabels(): array
    {
        $result = [];

        $rawItems = Product::select("grade")
            ->active()
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
            ->active()
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
            ->active()
            ->distinct()
            ->where("product_category",$product)
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
                ->active()
                ->distinct()
                ->where("product_category",$product)
                ->where("material",$material)
                ->get()
                ->toArray();

            foreach($rawItems as $rawItem){
                $result[] = $rawItem["grade"];
            }
        }
        else{
            $rawItems = Product::select("grade")
                ->active()
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

    public function getNestingLabelsFromProductCategory(string $productCategory): array
    {
        $result = [];

        $rawItems = Product::select("nesting_algo")
            ->active()
            ->distinct()
            ->where("product_category",$productCategory)
            ->get()
            ->toArray();

        foreach($rawItems as $rawItem){
            $result[] = $rawItem["nesting_algo"];
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
        foreach($nestingAlgos as $algo){
            $algoValues[] = $algo->value;
        }

        foreach($gradesGroups as $group){
            //Fasteners
            if($group === "FASTENERS"){
                foreach($fastenerGrades as $grade){
                    $gradesArray[$grade->value] = $algoValues;
                }
            }

            //Plain carbon
            if($group === "PLAIN_CARBON"){
                foreach($plainCarbonGrades as $grade){
                    $gradesArray[$grade->value] = $algoValues;
                }
            }

            //Stainless
            if($group === "STAINLESS"){
                foreach($stainlessGrades as $grade){
                    $gradesArray[$grade->value] = $algoValues;
                }
            }

            //Timber
            if($group === "TIMBER"){
                foreach($timberGrades as $grade){
                    $gradesArray[$grade->value] = $algoValues;
                }
            }

            //Plastic
            if($group === "PLASTIC"){
                foreach($plasticGrades as $grade){
                    $gradesArray[$grade->value] = $algoValues;
                }
            }

            //Alloys & aluminium
            if($group === "ALLOY"){
                foreach($alloyGrades as $grade){
                    $gradesArray[$grade->value] = $algoValues;
                }
            }

            //Hardox
            if($group === "HARDOX"){
                foreach($hardoxGrades as $grade){
                    $gradesArray[$grade->value] = $algoValues;
                }
            }
        }
        $gradesArray["Other"] = $algoValues;

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
            "Other" => [
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
            ]
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
                ["FASTENERS","PLAIN_CARBON"],
                [NestingEnums::METERAGE,NestingEnums::BUNDLE,NestingEnums::AREA]
            ),
            $this->buildDependencyComponent(
                MaterialEnums::STAINLESS_STEEL,
                ["FASTENERS","STAINLESS"],
                [NestingEnums::METERAGE,NestingEnums::BUNDLE,NestingEnums::AREA]
            ),
            $this->buildDependencyComponent(
                MaterialEnums::ALUMINIUM,
                ["ALLOY"],
                [NestingEnums::METERAGE,NestingEnums::BUNDLE,NestingEnums::AREA]
            ),
            $this->buildDependencyComponent(
                MaterialEnums::PLASTIC,
                ["PLASTIC"],
                [NestingEnums::METERAGE,NestingEnums::BUNDLE,NestingEnums::AREA]
            ),
            $this->buildDependencyComponent(
                MaterialEnums::TIMBER,
                ["TIMBER"],
                [NestingEnums::METERAGE,NestingEnums::BUNDLE,NestingEnums::AREA]
            ),
            $this->buildDependencyComponent(
                MaterialEnums::HARDOX,
                ["HARDOX"],
                [NestingEnums::METERAGE,NestingEnums::BUNDLE,NestingEnums::AREA]
            ),
            $this->buildDependencyOtherComponent(),
        );

        $sections = array_merge(
            $this->buildDependencyComponent(
                MaterialEnums::PLAIN_CARBON_STEEL,
                ["PLAIN_CARBON"],
                [NestingEnums::METERAGE]
            ),
            $this->buildDependencyComponent(
                MaterialEnums::STAINLESS_STEEL,
                ["STAINLESS"],
                [NestingEnums::METERAGE]
            ),
            $this->buildDependencyComponent(
                MaterialEnums::ALUMINIUM,
                ["ALLOY"],
                [NestingEnums::METERAGE]
            ),
            $this->buildDependencyOtherComponent(),
        );

        $plates = array_merge(
            $this->buildDependencyComponent(
                MaterialEnums::PLAIN_CARBON_STEEL,
                ["PLAIN_CARBON"],
                [NestingEnums::AREA]
            ),
            $this->buildDependencyComponent(
                MaterialEnums::STAINLESS_STEEL,
                ["STAINLESS"],
                [NestingEnums::AREA]
            ),
            $this->buildDependencyComponent(
                MaterialEnums::ALUMINIUM,
                ["ALLOY"],
                [NestingEnums::AREA]
            ),
            $this->buildDependencyOtherComponent(),
        );

        $fasteners = array_merge(
            $this->buildDependencyComponent(
                MaterialEnums::PLAIN_CARBON_STEEL,
                ["FASTENERS"],
                [NestingEnums::BUNDLE]
            ),
            $this->buildDependencyComponent(
                MaterialEnums::STAINLESS_STEEL,
                ["FASTENERS","STAINLESS"],
                [NestingEnums::BUNDLE]
            ),
            $this->buildDependencyComponent(
                MaterialEnums::ALUMINIUM,
                ["FASTENERS","ALLOY"],
                [NestingEnums::BUNDLE]
            ),
            $this->buildDependencyOtherComponent(),
        );

        $timber = array_merge(
            $this->buildDependencyComponent(
                MaterialEnums::TIMBER,
                ["TIMBER"],
                [NestingEnums::METERAGE]
            ),
            $this->buildDependencyOtherComponent(),
        );

        return [
            "Other" => $all,
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

    /**
     * @deprecated
     */
    public function buildDependencyArray(): array
    {
        //todo this is the dynamic version, but it's flawed. e.g the dependant data matches the database which defeats the point of creating new products
        /**
         * Dependency Array:
         *   - Product category (single)
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
        $allProductCategories = $this->allProductCategories();
        foreach($allProductCategories as $productCategory){
            //Materials
            $materialLabels = $this->getMaterialLabelsFromProduct($productCategory);
            foreach($materialLabels as $materialLabel){
                //Grades
                $gradeLabels = $this->getGradeLabelsFromMaterial($productCategory,$materialLabel);
                foreach($gradeLabels as $gradeLabel){
                    //Nesting
                    if($gradeLabel !== ""){
                        $nestingLabels = $this->getNestingLabelsFromProductCategory($productCategory);
                        foreach($nestingLabels as $nestingLabel){
                            $resultArray[$productCategory][$materialLabel][$gradeLabel][] = $nestingLabel;
                        }
                    }
                }
            }
        }

        return $resultArray;
    }

    public function piecesNested(Collection $pieces, array $lettersProjectArray): array
    {
        $byAlgo = $pieces->groupBy("nesting_algo");

        $piecesNested = [];
        foreach($byAlgo as $nestingAlgoLabel => $pieces){
            $piecesNested[] = $this->nesting($nestingAlgoLabel,$pieces,$lettersProjectArray);
        }

        return $piecesNested;
    }

    public function usage(array $piecesNested): array
    {
        /**
         * Sums of material totals, usage, and waste
         */
        $totalMaterial = 0;
        $totalUsedMaterial = 0;
        $totalWaste = 0;

        foreach($piecesNested as $items){
            foreach($items as $item){
                $item = (array) $item;
                if(isset($item["nested"]["totals"])){
                    $totals = $item["nested"]["totals"];

                    $totalMaterial = $totalMaterial + $totals["totalMaterial"];
                    $totalUsedMaterial = $totalUsedMaterial + $totals["totalUsedMaterial"];
                    $totalWaste = $totalWaste + $totals["totalWaste"];
                }
            }
        }

        return [
            "totalMaterial" => $totalMaterial,
            "totalUsedMaterial" => $totalUsedMaterial,
            "totalWaste" => $totalWaste,
            "efficiency" => $totalMaterial === 0
                ? 0
                : (round($totalUsedMaterial/$totalMaterial*100)),
        ];
    }

    public function piecesReadyForBatching(Business $business): Collection
    {
        //todo: timeline and status criteria needed
        $projectsReadyForBatching = $business->projectsReadyForBatching();

        $projectsForQuotingIds = $projectsReadyForBatching
            ->pluck("id")
            ->toArray();

        return Piece::query()
            ->whereIn("project_id",$projectsForQuotingIds)
            ->readyToBatch()
            ->get();
    }

    public function getLetterProjectArray(Collection $piecesReadyForBatching): array
    {
        $projectIds = [];
        foreach($piecesReadyForBatching as $piece){
            $projectIds[] = $piece->project_id;
        }
        $projectIds = array_values(array_unique($projectIds));

        $lettersProjectArray = [];

        foreach($projectIds as $index => $id){
            $letter = match ($index) {
                0 => "A",
                1 => "B",
                2 => "C",
                3 => "D",
                4 => "E",
                5 => "F",
                6 => "G",
                7 => "H",
                8 => "I",
                9 => "J",
            };

            $lettersProjectArray[$id] = $letter;
        }

        return $lettersProjectArray;
    }

    /**
     * @deprecated
     */
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

    public function batchGroups(array $piecesNested, Business $business): array
    {
        $supplierGroups = (new SupplierService())->supplierGroups($business);

        $resultAssigned = [];
        $resultUnassigned = [];
        foreach($piecesNested as $algoGroup){
            foreach($algoGroup as $piece){
                //Check if product is in batch group
                $piece = (array) $piece;

                $product = $piece["product_category"];
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

    public function nesting(string $nestingAlgoLabel, Collection $allPieces, array $lettersProjectArray): Collection
    {
        //Services
        $productService = new ProductService();

        $result = [];

        //METERAGE
        if($nestingAlgoLabel === NestingEnums::METERAGE->value){
            //Group pieces by product category
            $piecesByproductCategory = $allPieces->groupBy("product_category");
            foreach($piecesByproductCategory as $productCategory => $pieces){
                $generalProductDefinition = $productService->generalProductDefinition($productCategory);

                //Product definition
                $allFieldsIndividual = [];
                foreach($generalProductDefinition["mandatory"] as $field){
                    $allFieldsIndividual[$field] = false;
                }

                //Fields
                $fieldLabels = array_keys($allFieldsIndividual);

                //Unique piece specs
                $uniquePieceSpecs = Piece::select($fieldLabels)
                    ->whereIn("id",$pieces->pluck("id")->toArray())
                    ->where("product_category",$productCategory)
                    ->distinct()
                    ->get()
                    ->toArray();

                //Loop each unique piece specs
                foreach($uniquePieceSpecs as $uniquePieceSpec){
                    /*
                     * Get pieces that match spec
                     */
                    $pieces = $allPieces;
                    foreach($uniquePieceSpec as $field => $value){
                        $pieces = $pieces->where($field,$value);
                    }
                    $pieces->sortBy("actual_length");

                    //Piece spec
                    $appended = (object) $uniquePieceSpec;

                    //Derived product label. e.g "200PFC SS316"
                    $appended->product_derived_label = $productService->getDerivedProductLabel($uniquePieceSpec);

                    //Nesting algorithm
                    $appended->algo = $nestingAlgoLabel;

                    //Pieces array
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

                    //Purchasables
                    $purchasableVariations = $this->getPurchasableVariations($uniquePieceSpec,$nestingAlgoLabel);

                    $appended->pieces = $piecesArray;
                    $appended->purchasable = $purchasableVariations;
                    $appended->nested = $this->meterageAlgorithm($cutLengths,$purchasableVariations,$lettersProjectArray);

                    $result[] = $appended;
                }
            }
        }
        //AREA
        if($nestingAlgoLabel === NestingEnums::AREA->value){

            //Group pieces by product category
            $piecesByproductCategory = $allPieces->groupBy("product_category");
            foreach($piecesByproductCategory as $productCategory => $pieces) {
                $generalProductDefinition = $productService->generalProductDefinition($productCategory);

                //Product definition
                $allFieldsIndividual = [];
                foreach ($generalProductDefinition["mandatory"] as $field) {
                    $allFieldsIndividual[$field] = false;
                }

                //Fields
                $fieldLabels = array_keys($allFieldsIndividual);

                //Unique piece specs
                $uniquePieceSpecs = Piece::select($fieldLabels)
                    ->whereIn("id", $pieces->pluck("id")->toArray())
                    ->where("product_category",$productCategory)
                    ->distinct()
                    ->get()
                    ->toArray();

                //Loop each unique piece specs
                foreach($uniquePieceSpecs as $uniquePieceSpec) {
                    /*
                     * Get pieces that match spec
                     */
                    $pieces = $allPieces;
                    foreach ($uniquePieceSpec as $field => $value) {
                        $pieces = $pieces->where($field, $value);
                    }
                    $pieces->sortBy("product_category"); //todo something more useful

                    //Material spec
                    $appended = (object) $uniquePieceSpec;

                    //Derived product label. e.g "200PFC SS316"
                    $appended->product_derived_label = $productService->getDerivedProductLabel($uniquePieceSpec);

                    //Nesting algorithm
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
        }
        //BUNDLE
        if($nestingAlgoLabel === NestingEnums::BUNDLE->value){

            //Group pieces by product category
            $piecesByproductCategory = $allPieces->groupBy("product_category");
            foreach($piecesByproductCategory as $productCategory => $pieces) {
                $generalProductDefinition = $productService->generalProductDefinition($productCategory);

                //Product definition
                $allFieldsIndividual = [];
                foreach ($generalProductDefinition["mandatory"] as $field) {
                    $allFieldsIndividual[$field] = false;
                }

                //Fields
                $fieldLabels = array_keys($allFieldsIndividual);

                //Unique piece specs
                $uniquePieceSpecs = Piece::select($fieldLabels)
                    ->whereIn("id", $pieces->pluck("id")->toArray())
                    ->where("product_category",$productCategory)
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
                    $pieces->sortBy("product_category"); //todo something more useful

                    //Material spec
                    $appended = (object) $uniquePieceSpec;

                    //Derived product label. e.g "200PFC SS316"
                    $appended->product_derived_label = $productService->getDerivedProductLabel($uniquePieceSpec);

                    //Nesting algorithm
                    $appended->algo = $nestingAlgoLabel;

                    $piecesArray = [];
                    $boxSizes = $this->getPurchasableVariations($uniquePieceSpec,$nestingAlgoLabel);

                    $totalQty = 0;
                    foreach ($pieces as $piece) {
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
                    $appended->nested = $this->bundleAlgorithm($totalQty, $boxSizes);

                    $result[] = $appended;
                }
            }
        }

        return collect($result);
    }

    function getPurchasableVariations(array $pieceSpec, string $algo): array
    {
        /**
         * METERAGE = nominal_length
         * AREA = nominal_length & nominal_width
         * BUNDLE = pack size
         */

        $result = [];

        //METERAGE = nominal_length
        if($algo === NestingEnums::METERAGE->value){
            $query = Product::query();
            foreach($pieceSpec as $field => $value){
                $query->where($field,$value);
            }

            $result = $query->pluck("nominal_length")
                ->unique()
                ->toArray();
        }
        //AREA = nominal_length & nominal_width
        if($algo === NestingEnums::AREA->value){
            //todo 2D no area nesting yet

        }
        //BUNDLE = pack size
        if($algo === NestingEnums::BUNDLE->value){

            $query = Product::query();
            foreach($pieceSpec as $field => $value){
                $query->where($field,$value);
            }
            $allPacks = $query->get(["pack_size_1","pack_size_2","pack_size_3"])->toArray();

            $result = isset($allPacks[0])
                ? array_unique(array_values($allPacks[0]))
                : null;
        }

        return $result;
    }

    function meterageAlgorithm(array $cutLengths, array $stockLengths, array $lettersProjectArray): array
    {
        // Sort cut lengths in descending order (FFD heuristic)
        //rsort($cutLengths);

        usort($cutLengths, function ($a, $b) {
            return $b['length'] <=> $a['length']; //descending order
        });

        // Initialize an array to represent the used stock bars
        $usedStockBars = [];
        $unfitCuts = []; // Cuts that cannot be placed in any stock bar

        $totalMaterial = 0;
        $totalUsedMaterial = 0;
        $totalWaste = 0;

        // Process each cut length
        foreach ($cutLengths as $cut) {
            $placed = false;

            // Try to place the cut into an existing stock bar
            foreach ($usedStockBars as &$stock) {
                $cutLength = (int) $cut["length"];

                if ($stock['waste'] >= $cutLength) {
                    $stock['pieces'][] = [
                        "cutLength" => $cutLength,
                        "projectId" => $cut["project"],
                        "letter" => $lettersProjectArray[$cut["project"]],
                    ];
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
                            'stock_length' => $stockLength,
                            'waste' => $stockLength - $cutLength,
                            'pieces' => [[
                                "cutLength" => $cutLength,
                                "projectId" => $cut["project"],
                                "letter" => $lettersProjectArray[$cut["project"]],
                            ]],
                        ];

                        //Totals
                        $totalMaterial = $totalMaterial + $stockLength;
                        $totalUsedMaterial = $totalUsedMaterial + $cutLength;
                        $totalWaste = $totalWaste + ($stockLength - $cutLength);

                        $newStockPlaced = true;
                        break;
                    }
                }

                // If no new stock bar can accommodate the cut, add it to unfit cuts
                if (!$newStockPlaced) {
                    $unfitCuts[] = [
                        "project" => $cut["project"],
                        "length" => $cut["length"],
                        "letter" => $lettersProjectArray[$cut["project"]],
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
            "totals" => [
                "totalMaterial" => $totalMaterial,
                "totalUsedMaterial" => $totalUsedMaterial,
                "totalWaste" => $totalWaste,
            ],
        ];
    }

    public function bundleAlgorithm(int $totalQty, array $boxSizes): array
    {
        $originalQty = $totalQty;

        // Sort the box sizes in descending order
        rsort($boxSizes);

        //Remove any empty values
        $boxSizes = array_filter($boxSizes, function($value) {
            return $value !== "" && $value !== null;
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
//                            'stock_length' => $stockLength,
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
            $newResult[] = $bar["stock_length"];
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

    public function getNestingGroups(): array
    {
        $nestingGroups = [];

        //Meterage
        $products = Product::query()
            ->where("nesting_algo",NestingEnums::METERAGE->value)
            ->pluck("product_category")
            ->unique()
            ->toArray();
        $nestingGroups[NestingEnums::METERAGE->value] = array_values($products);


        //Area
        $products = Product::query()
            ->where("nesting_algo",NestingEnums::AREA->value)
            ->pluck("product_category")
            ->unique()
            ->toArray();
        $nestingGroups[NestingEnums::AREA->value] = array_values($products);


        //Bundle
        $products = Product::query()
            ->where("nesting_algo",NestingEnums::BUNDLE->value)
            ->pluck("product_category")
            ->unique()
            ->toArray();
        $nestingGroups[NestingEnums::BUNDLE->value] = array_values($products);

        return $nestingGroups;
    }

    public function getBatchDataForView(string $type, Business $business, Batch|null $batch): array
    {
        $piecesNested = null;
        $projectsForBatching = null;
        $batchGroups = null;
        $usage = null;

        //Batch (after batch object exists)
        if($type === "BATCH"){
            //Projects in batch
            $projectsForBatching = $batch->projects();

            //Pieces ready for batching
            $piecesInBatch = $batch->pieces;

            //Letter-project array
            $lettersProjectArray = $this->getLetterProjectArray($piecesInBatch);

            //Pieces nested
            $piecesNested = $this->piecesNested($piecesInBatch,$lettersProjectArray);

            //Nesting stats
            $usage = $this->usage($piecesNested);

            //Grouped by nesting algorithm
            $batchGroups = $this->batchGroups($piecesNested,$business);
        }
        //Suggested (pre-batch at nesting phase)
        if($type === "SUGGESTED"){
            //Projects ready for batching
            $projectsForBatching = $business->projectsReadyForBatching();

            //Pieces ready for batching
            $piecesReadyForBatching = $this->piecesReadyForBatching($business);

            //Letter-project array
            $lettersProjectArray = $this->getLetterProjectArray($piecesReadyForBatching);

            //Pieces nested
            $piecesNested = $this->piecesNested($piecesReadyForBatching,$lettersProjectArray);

            //Nesting stats
            $usage = $this->usage($piecesNested);

            //Grouped by nesting algorithm
            $batchGroups = $this->batchGroups($piecesNested,$business);
        }

        return [
            "pieces" => $piecesNested,
            "projectsReadyForBatching" => ProjectResource::collection($projectsForBatching),
            "batchGroups" => $batchGroups,
            "usage" => $usage,
            "type" => $type,
        ];
    }
}

