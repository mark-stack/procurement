<?php

namespace App\Services;

use App\Enums\MaterialEnums;
use App\Enums\MeasurementUnitEnums;
use App\Enums\GradeEnums;
use App\Enums\NestingEnums;
use App\Enums\ProductEnums;
use App\Enums\SurfaceEnums;
use App\Models\Business;
use App\Models\Piece;
use App\Models\Product;
use App\Models\RawMaterialQuote;
use App\Models\Template;
use App\Models\User;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;

class DataClassificationService
{
    public function findGeneralProductMatches(
        $user,
        $productString,
        $materialEnum,
        $gradesEnums,
        $surfaceEnum,
        $measurementUnitEnum,
        $nominalLengthInt,
        $nominalWidthInt,
        $nominalHeightInt,
    ): Collection
    {
        /**
         * Single purpose: find product matches independent of the length variations. e.g 200PFC
         */

        /**
         * Compare the limited attributes provided (grade, size, etc) against the master price book.
         *
         * NOTE: for METERAGE items, disregard length. e.g "150PFC STEEL GR300" disregards 9m,12m,etc.
         * NOTE: for AREA items, disregard length & width
         * NOTE: for BUNDLE items, disregard none
         */
        $results = [];

        $nestingArray = (new NestingService())->getNestingLabelsFromProductCategory($productString);

        //"Product" is mandatory
        if($productString && count($nestingArray) > 0) {
            //Loop different nesting algos. e.g ALLTHREAD has bundle and meterage
            foreach($nestingArray as $algo){
                $sizeInclude = [];
                $query = null;

                //METERAGE
                if($algo === NestingEnums::METERAGE->value){
                    $sizeInclude = ["nominal_width","nominal_height"];
                    $query = Product::select('product_category', 'material', 'grade', 'surface', 'nominal_units',"nominal_width",'nominal_height')
                        ->distinct()
                        ->availableFor($user)
                        ->where("product_category", $productString)
                        ->where("nesting_algo",$algo);
                }
                //AREA
                if($algo === NestingEnums::AREA->value){
                    $sizeInclude = ["nominal_height"];
                    $query = Product::select('product_category', 'material', 'grade', 'surface', 'nominal_units', 'nominal_height')
                        ->distinct()
                        ->availableFor($user)
                        ->where("product_category", $productString)
                        ->where("nesting_algo",$algo);
                }
                //BUNDLE
                if($algo === NestingEnums::BUNDLE->value){
                    $sizeInclude = ["nominal_length","nominal_width"];
                    $query = Product::select('product_category', 'material', 'grade', 'surface', 'nominal_units', 'nominal_length','nominal_width')
                        ->distinct()
                        ->availableFor($user)
                        ->where("product_category", $productString)
                        ->where("nesting_algo",$algo);
                }

                //Material
                if (!is_null($materialEnum)) {
                    $query->where("material", $materialEnum->value);
                }

                //Grade
                if (!is_null($gradesEnums)) {
                    $gradesArrayValues = [];
                    foreach($gradesEnums as $grade){
                        $gradesArrayValues[] = $grade->value;
                    }
                    $query->whereIn("grade",$gradesArrayValues);
                }

                //Surface
                if (!is_null($surfaceEnum)) {
                    $query->where("surface", $surfaceEnum->value);
                }

                //Measurement Unit
                if (!is_null($measurementUnitEnum)) {
                    $query->where("nominal_units", $measurementUnitEnum->value);
                }

                //Nominal length
                if (!is_null($nominalLengthInt) && in_array("nominal_length",$sizeInclude)) {
                    $query->where("nominal_length", $nominalLengthInt);
                }

                //Nominal width
                if (!is_null($nominalWidthInt) && in_array("nominal_width",$sizeInclude)) {
                    $query->where("nominal_width", $nominalWidthInt);
                }

                //Nominal height
                if (!is_null($nominalHeightInt) && in_array("nominal_height",$sizeInclude)) {
                    $query->where("nominal_height", $nominalHeightInt);
                }

                if($query->count() > 0){
                    foreach($query->get()->toArray() as $item){
                        $results[] = $item;
                    }
                }
            }
        }

        return collect($results);
    }

    public function findGeneralProductMatchesFromText(?string $text, User $user): Collection
    {
        $productConfig = $this->findProductConfigFromText($text);

        //MATERIAL
        $materialEnum = $this->findMaterial($productConfig,$text);

        //GRADE
        $gradesEnums = $this->findGrades($productConfig,$text);

        //SURFACE
        $surfaceEnum = $this->findSurface($productConfig,$text,$gradesEnums);

        //NOMINAL UNITS
        $measurementUnitEnum = $this->findMeasurementUnit($productConfig);

        //NOMINAL LENGTH
        $nominalLengthInt = $this->findNominal($productConfig,$text,"nominalLengthRegex");

        //NOMINAL WIDTH
        $nominalWidthInt = $this->findNominal($productConfig,$text,"nominalWidthRegex");

        //NOMINAL HEIGHT
        $nominalHeightInt = $this->findNominal($productConfig,$text,"nominalHeightRegex");

        return $this->findGeneralProductMatches(
            $user,
            $productConfig["productCategory"],
            $materialEnum,
            $gradesEnums,
            $surfaceEnum,
            $measurementUnitEnum,
            $nominalLengthInt,
            $nominalWidthInt,
            $nominalHeightInt
        );
    }

    public function findProductConfigFromText(?string $text): ?array
    {
        /**
         * Single purpose: extract a 'product_category' from text. e.g "PFC"
         */

        $resultProductConfigs = [];

        $productConfigs = (new ProductService())->getProductConfigs();

        foreach($productConfigs as $productConfig){
            //negative keywords
            $containsNegativeKeywords = false;
            foreach($productConfig["negativeKeywords"] as $negativeKeyword){
                if($this->containsSubstring($text, $negativeKeyword)){
                    $containsNegativeKeywords = true;
                }
            }

            //regex check
            if(!$containsNegativeKeywords){
                foreach($productConfig["productRegex"] as $pattern){
                    $regex = "/".$pattern."/i";
                    if(preg_match($regex, $text)){
                        if(!in_array($productConfig,$resultProductConfigs)){
                            $resultProductConfigs[] = $productConfig;
                        }
                    }
                }
            }
        }


        /**
         * If multiple results, do priority ranking to take best result.
         */
        $resultProductConfig = null;
        if(count($resultProductConfigs) === 1){
            $resultProductConfig = $resultProductConfigs[0];
        }
        if(count($resultProductConfigs) > 1){
            //Default take first result
            $resultProductConfig = $resultProductConfigs[0];
        }

        return $resultProductConfig;
    }

    private function containsSubstring(string $haystack, string $needle): bool {
        return $needle !== '' && stripos($haystack, $needle) !== false;
    }

    public function findMaterial($productConfig,$text): MaterialEnums
    {
        /**
         * Single purpose: extract a 'material' from text. e.g "SS304"
         */

        $materialResult = null;

        $materials = [
            //SS304
            [
                "materialEnum" => MaterialEnums::SS304,
                "regex" => [
                    "SS304",
                    "SS+\s+304",
                    "304SS",
                    "304+\s+SS",
                ],
            ],
            //SS316
            [
                "materialEnum" => MaterialEnums::SS316,
                "regex" => [
                    "SS316",
                    "SS+\s+316",
                    "316SS",
                    "316+\s+SS",
                ],
            ],
        ];

        foreach($materials as $material){
            foreach($material["regex"] as $pattern){
                $regex = "/".$pattern."/i";
                if(preg_match($regex, $text)){
                    $materialResult = $material["materialEnum"];
                }
            }
        }

        /**
         * Default material
         */
        if(!$materialResult){
            $materialResult = $productConfig["defaultMaterial"];
        }

        return $materialResult;
    }

    public function findGrades($productConfig,$text): null|array
    {
        /**
         * Single purpose: extract a 'grade' from text. e.g "GR 250"
         */

        $gradeResults = null;

        $grades = [
            //GR 250
            [
                "gradeEnum" => GradeEnums::GR250,
                "regex" => [
                    "Mild",
                    "MS",
                    "GR250",
                    "GRADE+\s+250",
                    "250MPA",
                    "250+\s+MPA",
                ],
            ],
            //GR 300
            [
                "gradeEnum" => GradeEnums::GR300,
                "regex" => [
                    "Mild",
                    "MS",
                    "GR300",
                    "GRADE+\s+300",
                    "300MPA",
                    "300+\s+MPA",
                ],
            ],
            //GR 350
            [
                "gradeEnum" => GradeEnums::GR350,
                "regex" => [
                    "Mild",
                    "MS",
                    "GR350",
                    "GRADE+\s+350",
                    "350MPA",
                    "350+\s+MPA",
                ],
            ],
            //todo: move this to material find
//            //SS304
//            [
//                "gradeEnum" => GradeEnums::SS304,
//                "regex" => [
//                    "SS304",
//                    "SS+\s+304",
//                    "304SS",
//                    "304+\s+SS",
//                ],
//            ],
//            //SS316
//            [
//                "gradeEnum" => GradeEnums::SS316,
//                "regex" => [
//                    "SS316",
//                    "SS+\s+316",
//                    "316SS",
//                    "316+\s+SS",
//                ],
//            ],
            //GR 4.6
            [
                "gradeEnum" => GradeEnums::GR_4_6,
                "regex" => [
                    "4.6",
                ],
            ],
            //GR 8.8
            [
                "gradeEnum" => GradeEnums::GR_8_8,
                "regex" => [
                    "8.8",
                ],
            ],
            //todo more
        ];

        foreach($grades as $grade){
            foreach($grade["regex"] as $pattern){
                $regex = "/".$pattern."/i";
                if(preg_match($regex, $text)){
                    $gradeResults[] = $grade["gradeEnum"];
                }
            }
        }

        /**
         * Default grade
         */
//        if(!$gradeResults){
//            $gradeResults = [
//                $product["defaultGrade"]
//            ];
//        }

        return $gradeResults;
    }

    public function findSurface($productConfig,$text,$foundGrades): ?SurfaceEnums
    {
        /**
         * Single purpose: extract a 'surface' from text. e.g "Painted"
         */

        $surfaceResult = null;

        $surfaces = [
            [
                "surfaceEnum" => SurfaceEnums::PAINTED,
                "regex" => [
                    "painted",
                ],
            ],
            [
                "surfaceEnum" => SurfaceEnums::GALVANISED,
                "regex" => [
                    "galvanised",
                    "galvanise",
                    "galvanized",
                    "galvanize",
                    "gal",
                    "galv",
                ],
            ],
            [
                "surfaceEnum" => SurfaceEnums::PASSIVATED,
                "regex" => [
                    "passivated",
                ],
            ],
            [
                "surfaceEnum" => SurfaceEnums::TREATED_H2,
                "regex" => [
                    "h2",
                ],
            ],
            [
                "surfaceEnum" => SurfaceEnums::TREATED,
                "regex" => [
                    "treated",
                ],
            ],
            //todo more
        ];

        foreach($surfaces as $surface){
            foreach($surface["regex"] as $pattern){
                $regex = "/".$pattern."/i";
                if(preg_match($regex, $text)){
                    $surfaceResult = $surface["surfaceEnum"];
                }
            }
        }

        /**
         * Default surface
         */
//        if(!$surfaceResult){
//            $isException = false;
//            $foundGrades = $foundGrades === null
//                ? []
//                : $foundGrades;
//
//            //SS316 bolts
//            if($product["productEnum"] === ProductEnums::BOLT && in_array(GradeEnums::SS316,$foundGrades)){
//                $isException = true;
//            }
//
//            //SS304 bolts
//            if($product["productEnum"] === ProductEnums::BOLT && in_array(GradeEnums::SS304,$foundGrades)){
//                $isException = true;
//            }
//
//            if(!$isException){
//                $surfaceResult = $product["defaultSurface"];
//            }
//        }

        return $surfaceResult;
    }

    public function findMeasurementUnit($productConfig): MeasurementUnitEnums
    {
        /**
         * Single purpose: get the measurement units from the product
         */

        return $productConfig["measurementUnit"] ?? MeasurementUnitEnums::SINGLE;
    }
    public function findNominal(array $productConfig, string $text, string $regexLabel): ?int
    {
        /**
         * Single purpose: extracts the number from string. e.g "200" from "200PFC"
         */

        $resultInt = null;

        $regexPatterns = $productConfig[$regexLabel];

        foreach($regexPatterns as $pattern){
            $regex = "/".$pattern."/i";
            preg_match_all($regex, $text, $matches);
            if (!empty($matches[1])) {
                foreach ($matches[1] as $number) {
                    $resultInt = (int) $number;
                }
            }
        }

        return $resultInt;
    }
}

