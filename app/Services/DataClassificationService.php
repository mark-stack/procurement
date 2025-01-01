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
        object $user,
        string $productString,
        ?object $materialEnum,
        ?array $gradesEnums,
        ?object $surfaceEnum,
        ?object $measurementUnitEnum,
        ?float $uncertainLengthFloat,
        ?float $uncertainWidthFloat,
        ?float $uncertainHeightFloat,
        ?float $wall,
        ?float $kg_per_m,
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
                    $sizeInclude = ["nominal_width","nominal_height","wall","kg_per_m"];
                    $query = Product::select('product_category', 'material', 'grade', 'surface', 'nominal_units',"nominal_width","actual_width",'nominal_height',"actual_height","wall","kg_per_m")
                        ->distinct()
                        ->availableFor($user)
                        ->where("product_category", $productString)
                        ->where("nesting_algo",$algo);
                }
                //AREA
                if($algo === NestingEnums::AREA->value){
                    $sizeInclude = ["nominal_height"];
                    $query = Product::select('product_category', 'material', 'grade', 'surface', 'nominal_units', 'nominal_height',"actual_height")
                        ->distinct()
                        ->availableFor($user)
                        ->where("product_category", $productString)
                        ->where("nesting_algo",$algo);
                }
                //BUNDLE
                if($algo === NestingEnums::BUNDLE->value){
                    $sizeInclude = ["nominal_length","nominal_width"];
                    $query = Product::select('product_category', 'material', 'grade', 'surface', 'nominal_units', 'nominal_length',"actual_length",'nominal_width',"actual_width")
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

                //Length
                if (!is_null($uncertainLengthFloat) && in_array("nominal_length",$sizeInclude)) {
                    //It may not find possible equivalents. It's mainly for CHS and pipe
                    $possibleEquivalentsCHS = $productString === ProductEnums::CHS->value
                        ? $this->possibleEquivalentsCHS($uncertainLengthFloat)
                        : null;
                    if($possibleEquivalentsCHS){
                        foreach($possibleEquivalentsCHS as $equivalent){
                            $query->where(function($q) use($equivalent){
                                $q->where("nominal_length", $equivalent["nominal"])
                                  ->orWhere("actual_length", $equivalent["actual"])
                                  ->orWhere("actual_length", $equivalent["rounded"]);
                            });
                        }
                    }
                    //Otherwise assume it's nominal
                    else{
                        $query->where("nominal_length", $uncertainLengthFloat);
                    }
                }

                //Width
                if (!is_null($uncertainWidthFloat) && in_array("nominal_width",$sizeInclude)) {
                    //It may not find possible equivalents. It's mainly for CHS and pipe
                    $possibleEquivalentsCHS = $productString === ProductEnums::CHS->value
                        ? $this->possibleEquivalentsCHS($uncertainWidthFloat)
                        : null;

                    if($possibleEquivalentsCHS){
                        foreach($possibleEquivalentsCHS as $equivalent){
                            $query->where(function($q) use($equivalent){
                                $q->where("nominal_width", $equivalent["nominal"])
                                    ->orWhere("actual_width", $equivalent["actual"])
                                    ->orWhere("actual_width", $equivalent["rounded"]);
                            });
                        }
                    }
                    //Otherwise assume it's nominal
                    else{
                        $query->where("nominal_width", $uncertainWidthFloat);
                    }
                }

                //Height
                if (!is_null($uncertainHeightFloat) && in_array("nominal_height",$sizeInclude)) {
                    //It may not find possible equivalents. It's mainly for CHS and pipe
                    $possibleEquivalentsCHS = $productString === ProductEnums::CHS->value
                        ? $this->possibleEquivalentsCHS($uncertainHeightFloat)
                        : null;

                    if($possibleEquivalentsCHS){
                        foreach($possibleEquivalentsCHS as $equivalent){
                            $query->where(function($q) use($equivalent){
                                $q->where("nominal_height", $equivalent["nominal"])
                                    ->orWhere("actual_height", $equivalent["actual"])
                                    ->orWhere("actual_height", $equivalent["rounded"]);
                            });
                        }
                    }
                    //Otherwise assume it's nominal
                    else{
                        $query->where(function($q) use($uncertainHeightFloat){
                            $q->where("nominal_height", $uncertainHeightFloat)
                              ->orWhere("nominal_height", round($uncertainHeightFloat));
                        });
                    }
                }

                //Wall
                if (!is_null($wall) && in_array("wall",$sizeInclude)) {
                    $query->where("wall", $wall);
                }

                //Weight
                if (!is_null($kg_per_m) && in_array("kg_per_m",$sizeInclude)) {
                    $possibleEquivalentsUB = $productString === ProductEnums::UB->value
                        ? $this->possibleEquivalentsUB($kg_per_m)
                        : null;

                    $possibleEquivalentsUC = $productString === ProductEnums::UC->value
                        ? $this->possibleEquivalentsUC($kg_per_m)
                        : null;

                    $possibleEquivalents = $possibleEquivalentsUB ?? $possibleEquivalentsUC;

                    if(count($possibleEquivalents) > 0){
                        foreach($possibleEquivalents as $equivalent){
                            $query->where(function($q) use($equivalent){
                                $q->where("kg_per_m", $equivalent["nominal"])
                                  ->orWhere("kg_per_m", $equivalent["actual"])
                                  ->orWhere("kg_per_m", $equivalent["rounded"]);
                            });
                        }
                    }
                    //Otherwise assume it's nominal
                    else{
                        $query->where("kg_per_m", $uncertainHeightFloat);
                    }
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

    private function possibleEquivalentsCHS(float $possibleFloat): array
    {
        /*
         * Is whole number
         * Might be nominal, so check for actual equivalent
         * e.g 300.0 might be 324.0 or 323.9
         *
         * Might be rounded actual, so check for nominal or actual
         * e.g 324.0 might be 300.0 or 323.9
         */

        /*
         * Is decimal number
         * Might be actual, so check for nominal
         * e.g 323.9 might be 300.0 or 324.0
         */
        $possibleEquivalentsCHS = [];

        $matrixOfEquivalents = [
            //format = nominal,actual,rounded
            [14,14.0,14],
            [15,14.8,15],
            [18,18.0,18],
            [18,18.1,18],
            [18,18.2,18],
            [20,26.9,27],
            [22,22.2,22],
            [22,22.3,22],
            [23,23.4,23],
            [25,33.7,34],
            [25,25.4,25],
            [26,25.7,26],
            [30,29.8,30],
            [31,31.4,31],
            [32,42.4,42],
            [32,32.0,32],
            [37,37.2,37],
            [37,37.3,37],
            [40,48.3,48],
            [40,40.4,40],
            [46,46.2,46],
            [45,44.7,45],
            [50,60.3,60],
            [51,50.7,51],
            [52,52.2,52],
            [57,56.7,57],
            [54,53.7,54],
            [60,59.7,60],
            [60,59.5,60],
            [65,76.1,76],
            [67,67.1,67],
            [73,72.9,73],
            [75,74.6,75],
            [80,88.9,89],
            [82,82.1,82],
            [82,82.0,82],
            [90,89.5,90],
            [90,101.6,102],
            [92,92.4,92],
            [97,96.8,97],
            [100,114.3,114],
            [101,101.0,101],
            [113,113.0,113],
            [118,118.0,118],
            [125,139.7,140],
            [125,125.0,125],
            [137,137.0,137],
            [150,168.3,168],
            [165,165.1,165],
            [158,158.0,158],
            [200,219.1,219],
            [200,193.7,194],
            [250,273.1,273],
            [300,323.9,324],
            [350,355.6,356],
            [400,406.4,406],
            [450,457.0,457],
            [500,508.0,508],
            [600,610.0,610],
            [650,660.0,660],
            [700,711.0,711],
            [750,762.0,762],
            [800,813.0,813],
            [900,914.0,914],
            [1050,1067.0,1067],
        ];

        foreach($matrixOfEquivalents as $alternativeArray){
            if(in_array($possibleFloat,$alternativeArray)){
                $possibleEquivalentsCHS[] = [
                    "nominal" => $alternativeArray[0],
                    "actual" => $alternativeArray[1],
                    "rounded" => $alternativeArray[2],
                ];
            }
        }

        return $possibleEquivalentsCHS;
    }

    private function possibleEquivalentsUB(float $possibleFloat): array
    {
        /**
            360 UB 56.7 vs 360 UB 57
         */
        $possibleEquivalents = [];

        $matrixOfEquivalents = [
            //format = nominal,actual,rounded
            [14, 14.0, 14],
            [18, 18.0, 18],
            [18, 18.1, 18],
            [22, 22.2, 22],
            [18, 18.2, 18],
            [22, 22.3, 22],
            [25, 25.4, 25],
            [30, 29.8, 30],
            [26, 25.7, 26],
            [31, 31.4, 31],
            [37, 37.3, 37],
            [32, 32.0, 32],
            [40, 40.4, 40],
            [46, 46.2, 46],
            [45, 44.7, 45],
            [51, 50.7, 51],
            [57, 56.7, 57],
            [54, 53.7, 54],
            [60, 59.7, 60],
            [67, 67.1, 67],
            [75, 74.6, 75],
            [82, 82.1, 82],
            [82, 82.0, 82],
            [92, 92.4, 92],
            [101, 101.0, 101],
            [113, 113.0, 113],
            [125, 125.0, 125],
        ];

        foreach($matrixOfEquivalents as $alternativeArray){
            if(in_array($possibleFloat,$alternativeArray)){
                $possibleEquivalents[] = [
                    "nominal" => $alternativeArray[0],
                    "actual" => $alternativeArray[1],
                    "rounded" => $alternativeArray[2],
                ];
            }
        }

        return $possibleEquivalents;
    }

    private function possibleEquivalentsUC(float $possibleFloat): array
    {
        /**
        360 UB 56.7 vs 360 UB 57
         */
        $possibleEquivalents = [];

        $matrixOfEquivalents = [
            //format = nominal,actual,rounded
            [158, 158.0, 158],
            [137, 137.0, 137],
            [118, 118.0, 118],
            [97, 96.8, 97],
            [90, 89.5, 90],
            [73, 72.9, 73],
            [60, 59.5, 60],
            [52, 52.2, 52],
            [46, 46.2, 46],
            [37, 37.2, 37],
            [30, 30.0, 30],
            [23, 23.4, 23],
            [15, 14.8, 15],
        ];

        foreach($matrixOfEquivalents as $alternativeArray){
            if(in_array($possibleFloat,$alternativeArray)){
                $possibleEquivalents[] = [
                    "nominal" => $alternativeArray[0],
                    "actual" => $alternativeArray[1],
                    "rounded" => $alternativeArray[2],
                ];
            }
        }

        return $possibleEquivalents;
    }

    public function findGeneralProductMatchesFromText(?string $text, object $user): Collection
    {
        $generalProductMatches = collect([]);

        $productConfig = $this->findProductConfigFromText($text);

        if($productConfig){
            //MATERIAL
            $materialEnum = $this->findMaterial($productConfig,$text);

            //GRADE
            $gradesEnums = $this->findGrades($productConfig,$text);

            //SURFACE
            $surfaceEnum = $this->findSurface($productConfig,$text,$gradesEnums);

            //NOMINAL UNITS
            $measurementUnitEnum = $this->findMeasurementUnit($productConfig);

            //LENGTH
            $uncertainLengthFloat = $this->findNominal($productConfig,$text,"nominalLengthRegex");

            //WIDTH
            $uncertainWidthFloat = $this->findNominal($productConfig,$text,"nominalWidthRegex");

            //HEIGHT
            $uncertainHeightFloat = $this->findNominal($productConfig,$text,"nominalHeightRegex");

            //WALL
            $wall = $this->findNominal($productConfig,$text,"wallRegex");

            //Weight
            $kg_per_m = $this->findNominal($productConfig,$text,"weightRegex");

//            dd([
//                "text" => $text,
//                "uncertainLengthFloat" => $uncertainLengthFloat,
//                "uncertainWidthFloat" => $uncertainWidthFloat,
//                "uncertainHeightFloat" => $uncertainHeightFloat,
//                "wall" => $wall,
//                "kg_per_m" => $kg_per_m,
//                "gradesEnums" => $gradesEnums,
//                "productConfig" => $productConfig,
//            ]);

            $generalProductMatches = $this->findGeneralProductMatches(
                $user,
                $productConfig["productCategory"],
                $materialEnum,
                $gradesEnums,
                $surfaceEnum,
                $measurementUnitEnum,
                $uncertainLengthFloat,
                $uncertainWidthFloat,
                $uncertainHeightFloat,
                $wall,
                $kg_per_m,
            );
        }

        return $generalProductMatches;
    }

    public function findProductConfigFromText(?string $text): ?array
    {
        /**
         * Single purpose: extract a 'product_category' from text. e.g "PFC"
         */
        $productService = new ProductService();
        $resultProductConfigs = [];

        /**
         * Fasteners advanced classification
         * 1) Find one of: MX, bolt, csk, hd bolt, etc...
         * 2) Then do further classification based on keywords and lengths
         */
        $fastenersConfig = $this->findFastenersConfigFromText($text);
        if($fastenersConfig){
            $resultProductConfigs[] = $fastenersConfig;
        }

        /**
         * Standard classification
         * 1) Positive keywords (one mandatory?)
         * 2) Regex match (one mandatory?)
         */
        if(!$resultProductConfigs){
            $regularConfigs = $productService->getProductConfigs(false);
            foreach($regularConfigs as $regularConfig){
                //negative keywords
                $containsNegativeKeywords = false;
                foreach($regularConfig["negativeKeywords"] as $negativeKeyword){
                    if($this->containsSubstring($text, $negativeKeyword)){
                        $containsNegativeKeywords = true;
                    }
                }

                //regex check
                if(!$containsNegativeKeywords){
                    foreach($regularConfig["productRegex"] as $pattern){
                        $regex = "/".$pattern."/i";
                        if(preg_match($regex, $text)){
                            if(!in_array($regularConfig,$resultProductConfigs)){
                                $resultProductConfigs[] = $regularConfig;
                            }
                        }
                    }
                }
            }
        }

        /**
         * Take just 1 result
         */
        $resultProductConfig = null;
        if(count($resultProductConfigs) > 0){
            $resultProductConfig = $resultProductConfigs[0];
        }

        return $resultProductConfig;
    }

    public function findFastenersConfigFromText(?string $text): ?array
    {
        $resultFastenerConfig = null;

        //Services
        $productService = new ProductService();

        //First pass: any of "MX, Hex, bolt" etc
        $fastenersFound = $this->fastenersFoundInText($text);

        //Second pass
        if($fastenersFound){
            $resultFastenerConfigs = [];
            $fastenerConfigs = $productService->getProductConfigs(true);
            foreach($fastenerConfigs as $fastenerConfig){
                //negative keywords
                $containsNegativeKeywords = false;
                foreach($fastenerConfig["negativeKeywords"] as $negativeKeyword){
                    if($this->containsSubstring($text, $negativeKeyword)){
                        $containsNegativeKeywords = true;
                    }
                }

                //regex check
                if(!$containsNegativeKeywords){
                    foreach($fastenerConfig["productRegex"] as $pattern){
                        $regex = "/".$pattern."/i";
                        if(preg_match($regex, $text)){
                            $inArray = collect($resultFastenerConfigs)->where("productCategory",$fastenerConfig["productCategory"])->count() > 0;
                            if(!$inArray){
                                $resultFastenerConfigs[] = $fastenerConfig;
                            }
                        }
                    }
                }
            }

            //If no results, it means HEX_BOLT is default
            if(count($resultFastenerConfigs) === 0){
                $resultFastenerConfig = collect($fastenerConfigs)->where("productCategory",ProductEnums::HEX_BOLT->value)->first();
            }
            //If just one result
            if(count($resultFastenerConfigs) === 1){
                $resultFastenerConfig = $resultFastenerConfigs[0];
            }
            //If multiple results
            if(count($resultFastenerConfigs) > 1){
                //All fastener categories take priority over HEX_BOLT
                $removeHexBolt = [];
                foreach($resultFastenerConfigs as $config){
                    if($config["productCategory"] !== ProductEnums::HEX_BOLT->value){
                        $removeHexBolt[] = $config;
                    }
                }

                if(count($removeHexBolt) > 0){
                    $resultFastenerConfig = $removeHexBolt[0];
                }
                else{
                    $resultFastenerConfig = $resultFastenerConfigs[0];
                }
            }
        }

        return $resultFastenerConfig;
    }

    private function fastenersFoundInText(string $text): bool
    {
        /**
         * 1) Mx or bolt or chemset etc
         * 2) [Xmm or X mm] AND [bolt or chemset etc]
         */

        $fastenerTerms = [
            "hex","bolt","eye bolt", "u bolt",
            "CSK", "countersink", "countersunk",
            "anchor", "stud", "chemset", "chemical anchor", "hd bolt", "anchor rod",
            "allthread", "threaded rod",
            "nut",
            "washer",
            "screw",
            "rivets",
            "circlip",
        ];

        $resultMx = preg_match("/M\d+/i", $text) === 1;
        $resultXmm = preg_match("/\d+mm|\d+\s+mm/i", $text) === 1;

        //Pattern like  '/M\d+|\d+mm|mark|john|david/i'
        $regexTerms = '/'; // Use 'i' flag for case-insensitivity
        foreach($fastenerTerms as $index => $term){
            $regexTerms = $regexTerms.($index > 0 ? "|" : "").$term;
        }
        $regexTerms = $regexTerms."/i";
        $resultTerms = preg_match($regexTerms, $text) === 1;

        $cond1 = $resultMx || $resultTerms;
        $cond2 = $resultXmm && $resultTerms;

        return $cond1 || $cond2;
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
                    "4\.6",
                ],
            ],
            //GR 8.8
            [
                "gradeEnum" => GradeEnums::GR_8_8,
                "regex" => [
                    "8\.8",
                ],
            ],
            //GR 12.9
            [
                "gradeEnum" => GradeEnums::GR_12_9,
                "regex" => [
                    "12\.9",
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
    public function findNominal(array $productConfig, string $text, string $regexLabel): ?float
    {
        /**
         * Single purpose: extracts the number from string. e.g "200" from "200PFC"
         */

        $resultFloat = null;

        $regexPatterns = $productConfig[$regexLabel];

        foreach($regexPatterns as $pattern){
            $regex = "/".$pattern."/i";

            preg_match_all($regex, $text, $matches);

            if(!empty($matches[0][0])){
                preg_match_all('/-?\d+(\.\d+)?/i', $matches[0][0], $matches);
                if(!empty($matches[0][0])){
                    $resultFloat = (float) $matches[0][0];
                }
            }
        }

        return $resultFloat;
    }
}

