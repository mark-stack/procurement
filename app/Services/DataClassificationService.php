<?php

namespace App\Services;

use App\Enums\GradeEnums;
use App\Enums\MaterialEnums;
use App\Enums\MeasurementUnitEnums;
use App\Enums\ProductEnums;
use App\Enums\SurfaceEnums;
use App\Models\Product;

class DataClassificationService
{
    public function findGeneralProductMatches(
        object $user,
        string $productCategory,
        ?object $materialEnum,
        ?array $gradesEnums,
        ?object $surfaceEnum,
        ?object $measurementUnitEnum,
        ?float $uncertainLengthFloat,
        ?float $uncertainWidthFloat,
        ?float $uncertainHeightFloat,
        ?float $wall,
        ?float $kg_per_m,
    ): array {
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
        $results = null;

        //Implementation (service)
        $implementation = $this->findImplementationFromProductCategory($productCategory);
        $generalProductDefinition = $implementation
            ? $implementation->generalProductDefinition()
            : $this->fallbackGeneralProductDefinition();

        //Supplier group
        $config = $implementation->config();
        $supplierGroup = $config['supplierGroup']->value;

        //Product definition
        $allFieldsIndividual = [];
        foreach ($generalProductDefinition['mandatory'] as $field) {
            $allFieldsIndividual[$field] = false;
        }

        //Fields
        $fieldLabels = array_keys($allFieldsIndividual);

        //Product category is mandatory
        if ($productCategory) {
            $allFieldsIndividual['product_category'] = true;

            $query = Product::select($fieldLabels)
                ->distinct()
                ->availableFor($user)
                ->where('product_category', $productCategory);

            //Material
            if (! is_null($materialEnum) && in_array('material', $fieldLabels)) {
                $query->where('material', $materialEnum->value);

                $allFieldsIndividual['material'] = true;
            }
            //Grade
            if (! is_null($gradesEnums) && in_array('grade', $fieldLabels)) {
                $gradesArrayValues = [];
                foreach ($gradesEnums as $grade) {
                    $gradesArrayValues[] = $grade->value;
                }

                $query->whereIn('grade', $gradesArrayValues);

                $allFieldsIndividual['grade'] = true;
            }

            //Surface
            if (! is_null($surfaceEnum) && in_array('surface', $fieldLabels)) {
                $query->where('surface', $surfaceEnum->value);

                $allFieldsIndividual['surface'] = true;
            }

            //Measurement Unit
            if (! is_null($measurementUnitEnum) && in_array('nominal_units', $fieldLabels)) {
                $query->where('nominal_units', $measurementUnitEnum->value);

                $allFieldsIndividual['nominal_units'] = true;
            }

            //Length
            if (! is_null($uncertainLengthFloat) && in_array('nominal_length', $fieldLabels)) {
                //It may not find possible equivalents. It's mainly for CHS and pipe
                $possibleEquivalents = match ($productCategory) {
                    ProductEnums::CHS->value => $this->possibleEquivalentsCHS($uncertainLengthFloat),
                    default => [],
                };

                if (count($possibleEquivalents) > 0) {
                    foreach ($possibleEquivalents as $equivalent) {
                        $query->where(function ($q) use ($equivalent) {
                            $q->where('nominal_length', $equivalent['nominal'])
                                ->orWhere('precise_length', $equivalent['precise'])
                                ->orWhere('precise_length', $equivalent['rounded']);
                        });
                    }
                }
                //Otherwise assume it's nominal
                else {
                    $query->where('nominal_length', $uncertainLengthFloat);
                }

                $allFieldsIndividual['nominal_length'] = true;
            }

            //Width
            if (! is_null($uncertainWidthFloat) && in_array('nominal_width', $fieldLabels)) {
                //It may not find possible equivalents. It's mainly for CHS and pipe
                $possibleEquivalents = match ($productCategory) {
                    ProductEnums::CHS->value => $this->possibleEquivalentsCHS($uncertainWidthFloat),
                    default => [],
                };

                if (count($possibleEquivalents) > 0) {
                    foreach ($possibleEquivalents as $equivalent) {
                        $query->where(function ($q) use ($equivalent) {
                            $q->where('nominal_width', $equivalent['nominal'])
                                ->orWhere('precise_width', $equivalent['precise'])
                                ->orWhere('precise_width', $equivalent['rounded']);
                        });
                    }
                }
                //Otherwise assume it's nominal
                else {
                    $query->where('nominal_width', $uncertainWidthFloat);
                }

                $allFieldsIndividual['nominal_width'] = true;
            }

            //Height
            if (! is_null($uncertainHeightFloat) && in_array('nominal_height', $fieldLabels)) {
                //It may not find possible equivalents. It's mainly for CHS and pipe
                $possibleEquivalents = match ($productCategory) {
                    ProductEnums::CHS->value => $this->possibleEquivalentsCHS($uncertainHeightFloat),
                    default => [],
                };

                if (count($possibleEquivalents) > 0) {
                    foreach ($possibleEquivalents as $equivalent) {
                        $query->where(function ($q) use ($equivalent) {
                            $q->where('nominal_height', $equivalent['nominal'])
                                ->orWhere('precise_height', $equivalent['precise'])
                                ->orWhere('precise_height', $equivalent['rounded']);
                        });
                    }
                }
                //Otherwise assume it's nominal
                else {
                    $query->where(function ($q) use ($uncertainHeightFloat) {
                        $q->where('nominal_height', $uncertainHeightFloat)
                            ->orWhere('nominal_height', round($uncertainHeightFloat));
                    });
                }

                $allFieldsIndividual['nominal_height'] = true;
            }

            //Wall
            if (! is_null($wall) && in_array('wall', $fieldLabels)) {
                $query->where('wall', $wall);

                $allFieldsIndividual['wall'] = true;
            }

            //Weight
            if (! is_null($kg_per_m) && in_array('kg_per_m', $fieldLabels)) {
                $possibleEquivalents = match ($productCategory) {
                    ProductEnums::UB->value => $this->possibleEquivalentsUB($kg_per_m),
                    ProductEnums::UC->value => $this->possibleEquivalentsUC($kg_per_m),
                    default => [],
                };

                if (count($possibleEquivalents) > 0) {
                    foreach ($possibleEquivalents as $equivalent) {
                        $query->where(function ($q) use ($equivalent) {
                            $q->where('kg_per_m', $equivalent['nominal'])
                                ->orWhere('kg_per_m', $equivalent['precise'])
                                ->orWhere('kg_per_m', $equivalent['rounded']);
                        });
                    }
                }
                //Otherwise assume it's nominal
                else {
                    $query->where('kg_per_m', $uncertainHeightFloat);
                }

                $allFieldsIndividual['kg_per_m'] = true;
            }

            if ($query->count() > 0) {
                foreach ($query->get()->toArray() as $item) {
                    $results[] = $item;
                }
            }
        }

        $allFields = array_reduce($allFieldsIndividual, fn ($carry, $item) => $carry && $item, true);

        return [
            'allFields' => $allFields,
            'allFieldsIndividual' => $allFieldsIndividual,
            'results' => $results ?? [],
            'supplierGroup' => $supplierGroup,
        ];
    }

    public function fallbackGeneralProductDefinition(): array
    {
        return [
            'mandatory' => [
                'product_category',
                'material',
                'grade',
                'surface',
                'nominal_units',
                'nominal_width',
                'nominal_height',
                'nominal_length',
                'precise_length',
                'precise_height',
                'precise_width',
                'wall',
                'kg_per_m',
            ],
            'exclude' => [

            ],
            'purchasableVariations' => [

            ],
        ];
    }

    public function findImplementationFromProductCategory(string $productString): ?object
    {
        $result = null;

        $implementations = (new ProductService)->getImplementations();
        foreach ($implementations as $implementation) {
            // Check if the class exists
            if (class_exists($implementation)) {
                $service = new $implementation;
                $config = $service->config();

                //Fasteners
                if (strtoupper($config['productCategory']) === strtoupper($productString)) {
                    $result = $service;
                }
            }
        }

        return $result;
    }

    public function findCustomProductMatches(
        string $description,
        object $user,
    ): array {
        /**
         * Single purpose: find custom matches independent of the length variations. e.g 200PFC
         */
        $results = [];

        //Get custom products
        $customProductMatchesAllVariations = $user->business->products()
            ->where('description', $description)
            ->get()
            ->groupBy('product_category')
            ->toArray();

        if (count($customProductMatchesAllVariations) > 0) {
            $resultsThisProductCategory = [];

            //Loop each product category
            foreach ($customProductMatchesAllVariations as $productCategory => $products) {
                //Implementation (service)
                $implementation = $this->findImplementationFromProductCategory($productCategory);
                $generalProductDefinition = $implementation
                    ? $implementation->generalProductDefinition()
                    : $this->fallbackGeneralProductDefinition();

                //Product definition
                $allFieldsIndividual = [];
                foreach ($generalProductDefinition['mandatory'] as $field) {
                    $allFieldsIndividual[$field] = false;
                }

                //Fields
                $fieldLabels = array_keys($allFieldsIndividual);

                //Get IDs
                $ids = collect($products)->pluck('id')->toArray();

                //Results
                $resultsThisProductCategory = Product::select($fieldLabels)
                    ->whereIn('id', $ids)
                    ->distinct()
                    ->availableFor($user)
                    ->get()
                    ->toArray();
            }

            foreach ($resultsThisProductCategory as $result) {
                $results[] = $result;
            }
        }

        return $results;
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
            [14, 14.0, 14],
            [15, 14.8, 15],
            [18, 18.0, 18],
            [18, 18.1, 18],
            [18, 18.2, 18],
            [20, 26.9, 27],
            [22, 22.2, 22],
            [22, 22.3, 22],
            [23, 23.4, 23],
            [25, 33.7, 34],
            [25, 25.4, 25],
            [26, 25.7, 26],
            [30, 29.8, 30],
            [31, 31.4, 31],
            [32, 42.4, 42],
            [32, 32.0, 32],
            [37, 37.2, 37],
            [37, 37.3, 37],
            [40, 48.3, 48],
            [40, 40.4, 40],
            [46, 46.2, 46],
            [45, 44.7, 45],
            [50, 60.3, 60],
            [51, 50.7, 51],
            [52, 52.2, 52],
            [57, 56.7, 57],
            [54, 53.7, 54],
            [60, 59.7, 60],
            [60, 59.5, 60],
            [65, 76.1, 76],
            [67, 67.1, 67],
            [73, 72.9, 73],
            [75, 74.6, 75],
            [80, 88.9, 89],
            [82, 82.1, 82],
            [82, 82.0, 82],
            [90, 89.5, 90],
            [90, 101.6, 102],
            [92, 92.4, 92],
            [97, 96.8, 97],
            [100, 114.3, 114],
            [101, 101.0, 101],
            [113, 113.0, 113],
            [118, 118.0, 118],
            [125, 139.7, 140],
            [125, 125.0, 125],
            [137, 137.0, 137],
            [150, 168.3, 168],
            [165, 165.1, 165],
            [158, 158.0, 158],
            [200, 219.1, 219],
            [200, 193.7, 194],
            [250, 273.1, 273],
            [300, 323.9, 324],
            [350, 355.6, 356],
            [400, 406.4, 406],
            [450, 457.0, 457],
            [500, 508.0, 508],
            [600, 610.0, 610],
            [650, 660.0, 660],
            [700, 711.0, 711],
            [750, 762.0, 762],
            [800, 813.0, 813],
            [900, 914.0, 914],
            [1050, 1067.0, 1067],
        ];

        foreach ($matrixOfEquivalents as $alternativeArray) {
            if (in_array($possibleFloat, $alternativeArray)) {
                $possibleEquivalentsCHS[] = [
                    'nominal' => $alternativeArray[0],
                    'precise' => $alternativeArray[1],
                    'rounded' => $alternativeArray[2],
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

        foreach ($matrixOfEquivalents as $alternativeArray) {
            if (in_array($possibleFloat, $alternativeArray)) {
                $possibleEquivalents[] = [
                    'nominal' => $alternativeArray[0],
                    'precise' => $alternativeArray[1],
                    'rounded' => $alternativeArray[2],
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

        foreach ($matrixOfEquivalents as $alternativeArray) {
            if (in_array($possibleFloat, $alternativeArray)) {
                $possibleEquivalents[] = [
                    'nominal' => $alternativeArray[0],
                    'precise' => $alternativeArray[1],
                    'rounded' => $alternativeArray[2],
                ];
            }
        }

        return $possibleEquivalents;
    }

    public function findGeneralProductMatchesFromText(?string $text, object $user): array
    {
        $generalProductMatches = [
            'allFields' => false,
            'allFieldsIndividual' => [],
            'results' => [],
            'supplierGroup' => null,
        ];

        $productConfig = $this->findProductConfigFromText($text);

        if ($productConfig) {
            //MATERIAL
            $materialEnum = $this->findMaterial($productConfig, $text);

            //GRADE
            $gradesEnums = $this->findGrades($productConfig, $text);

            //SURFACE
            $surfaceEnum = $this->findSurface($productConfig, $text);

            //NOMINAL UNITS
            $measurementUnitEnum = MeasurementUnitEnums::MILLIMETERS; //$this->findMeasurementUnit($productConfig);

            //LENGTH
            $uncertainLengthFloat = $this->findNumberByRegex($productConfig, $text, 'nominalLengthRegex');

            //WIDTH
            $uncertainWidthFloat = $this->findNumberByRegex($productConfig, $text, 'nominalWidthRegex');

            //HEIGHT
            $uncertainHeightFloat = $this->findNumberByRegex($productConfig, $text, 'nominalHeightRegex');

            //WALL
            $wall = $this->findNumberByRegex($productConfig, $text, 'wallRegex');

            //Weight
            $kg_per_m = $this->findNumberByRegex($productConfig, $text, 'weightRegex');

//            dd([
//                "text" => $text,
//                "surface" => $surfaceEnum,
//                "grade" => $gradesEnums,
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
                $productConfig['productCategory'],
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
         * Single purpose: extract a 'product_category' from text. e.g "PFC".
         * UPGRADE does all products. STANDARD does sections only
         */
        $productService = new ProductService;
        $resultProductConfigs = [];

        /**
         * Fasteners advanced classification
         * 1) Find one of: MX, bolt, csk, hd bolt, etc...
         * 2) Then do further classification based on keywords and lengths
         * UPGRADED can do fasteners
         */
        $fastenersConfig = $this->findFastenersConfigFromText($text);
        if ($fastenersConfig) {
            $resultProductConfigs[] = $fastenersConfig;
        }

        /**
         * Standard classification
         * 1) Positive keywords (one mandatory?)
         * 2) Regex match (one mandatory?)
         */
        if (! $resultProductConfigs) {
            $regularConfigs = $productService->getProductConfigs(false);
            foreach ($regularConfigs as $regularConfig) {
                //negative keywords
                $containsNegativeKeywords = false;
                foreach ($regularConfig['config']['negativeKeywords'] as $negativeKeyword) {
                    if ($this->containsSubstring($text, $negativeKeyword)) {
                        $containsNegativeKeywords = true;
                    }
                }

                //regex check
                if (! $containsNegativeKeywords) {
                    foreach ($regularConfig['config']['productRegex'] as $pattern) {
                        $regex = '/'.$pattern.'/i';
                        if (preg_match($regex, $text)) {
                            if (! in_array($regularConfig, $resultProductConfigs)) {
                                $resultProductConfigs[] = $regularConfig['config'];
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
        if (count($resultProductConfigs) > 0) {
            $resultProductConfig = $resultProductConfigs[0];
        }

        return $resultProductConfig;
    }

    public function findFastenersConfigFromText(?string $text): ?array
    {
        $resultFastenerConfig = null;

        //Services
        $productService = new ProductService;

        //First pass: any of "MX, Hex, bolt" etc
        $fastenersFound = $this->fastenersFoundInText($text);

        //Second pass
        if ($fastenersFound) {
            $resultFastenerConfigs = [];
            $fastenerConfigs = $productService->getProductConfigs(true);

            foreach ($fastenerConfigs as $fastenerConfig) {
                //negative keywords
                $containsNegativeKeywords = false;
                foreach ($fastenerConfig['config']['negativeKeywords'] as $negativeKeyword) {
                    if ($this->containsSubstring($text, $negativeKeyword)) {
                        $containsNegativeKeywords = true;
                    }
                }

                //regex check
                if (! $containsNegativeKeywords) {
                    foreach ($fastenerConfig['config']['productRegex'] as $pattern) {
                        $regex = '/'.$pattern.'/i';
                        if (preg_match($regex, $text)) {
                            $inArray = collect($resultFastenerConfigs)->where('productCategory', $fastenerConfig['config']['productCategory'])->count() > 0;
                            if (! $inArray) {
                                $resultFastenerConfigs[] = $fastenerConfig['config'];
                            }
                        }
                    }
                }
            }

            //If no results, it means HEX_BOLT is default
            if (count($resultFastenerConfigs) === 0) {
                foreach ($fastenerConfigs as $fastenerConfig) {
                    if ($fastenerConfig['config']['productCategory'] === ProductEnums::HEX_BOLT->value) {
                        $resultFastenerConfig = $fastenerConfig['config'];
                    }
                }
            }
            //If just one result
            if (count($resultFastenerConfigs) === 1) {
                $resultFastenerConfig = $resultFastenerConfigs[0];
            }
            //If multiple results
            if (count($resultFastenerConfigs) > 1) {
                //All fastener categories take priority over HEX_BOLT
                $removeHexBolt = [];
                foreach ($resultFastenerConfigs as $config) {
                    if ($config['productCategory'] !== ProductEnums::HEX_BOLT->value) {
                        $removeHexBolt[] = $config;
                    }
                }

                if (count($removeHexBolt) > 0) {
                    $resultFastenerConfig = $removeHexBolt[0];
                } else {
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
            'hex', 'bolt', 'eye bolt', 'u bolt',
            'CSK', 'countersink', 'countersunk',
            'anchor', 'stud', 'chemset', 'chemical anchor', 'hd bolt', 'anchor rod',
            'allthread', 'threaded rod',
            'nut',
            'washer',
            'screw',
            'rivets',
            'circlip',
        ];

        $resultMx = preg_match("/M\d+/i", $text) === 1;
        $resultXmm = preg_match("/\d+mm|\d+\s+mm/i", $text) === 1;

        //Pattern like  '/M\d+|\d+mm|mark|john|david/i'
        $regexTerms = '/'; // Use 'i' flag for case-insensitivity
        foreach ($fastenerTerms as $index => $term) {
            $regexTerms = $regexTerms.($index > 0 ? '|' : '').$term;
        }
        $regexTerms = $regexTerms.'/i';
        $resultTerms = preg_match($regexTerms, $text) === 1;

        $cond1 = $resultMx || $resultTerms;
        $cond2 = $resultXmm && $resultTerms;

        return $cond1 || $cond2;
    }

    private function containsSubstring(string $haystack, string $needle): bool
    {
        return $needle !== '' && stripos($haystack, $needle) !== false;
    }

    public function findMaterial(array $productConfig, string $text): MaterialEnums
    {
        /**
         * Single purpose: extract a 'material' from text. e.g "SS304"
         */
        $materialResult = null;

        $materials = [
            //STAINLESS_STEEL
            [
                'materialEnum' => MaterialEnums::STAINLESS_STEEL,
                'regex' => [
                    'SS304',        //SS304
                    "SS+\s+304",    //SS 304
                    '304SS',        //304SS
                    "304+\s+SS",    //304 SS
                    "304+\s+Stainless+\s+steel", //304 stainless steel
                    'SS316',        //SS316
                    "SS+\s+316",    //SS 316
                    '316SS',        //316SS
                    "316+\s+SS",    //316 SS
                    "316+\s+Stainless+\s+steel", //316 stainless steel
                ],
            ],
            //HARDOX
            [
                'materialEnum' => MaterialEnums::HARDOX,
                'regex' => [
                    'hardox',
                    //todo more
                ],
            ],
            //ALLOY
            [
                'materialEnum' => MaterialEnums::ALLOY,
                'regex' => [
                    'Chromium',
                    'Manganese',
                    'Nickel',
                    'Molybdenum',
                    'Duplex',
                    'Tool Steel',
                    'Tungsten',
                    'Spring Steel',
                    //todo more
                ],
            ],
            //Aluminium
            [
                'materialEnum' => MaterialEnums::ALUMINIUM,
                'regex' => [
                    'aluminium',
                    //todo more
                ],
            ],
            //Plastic
            [
                'materialEnum' => MaterialEnums::PLASTIC,
                'regex' => [
                    'plastic',
                    //todo more
                ],
            ],
        ];

        foreach ($materials as $material) {
            foreach ($material['regex'] as $pattern) {
                $regex = '/'.$pattern.'/i';
                if (preg_match($regex, $text)) {
                    $materialResult = $material['materialEnum'];
                }
            }
        }

        /**
         * Default material
         */
        if (! $materialResult) {
            $materialResult = $productConfig['defaultMaterial'];
        }

        return $materialResult;
    }

    public function findGrades($productConfig, $text): ?array
    {
        /**
         * Single purpose: extract a 'grade' from text. e.g "GR 250"
         */
        $gradeResults = null;

        $grades = [
            //GR250
            [
                'gradeEnum' => GradeEnums::GR250,
                'regex' => [
                    'Mild',
                    'MS',
                    'GR250',
                    "GRADE+\s+250",
                    '250MPA',
                    "250+\s+MPA",
                ],
            ],
            //GR300
            [
                'gradeEnum' => GradeEnums::GR300,
                'regex' => [
                    'Mild',
                    'MS',
                    'GR300',
                    "GRADE+\s+300",
                    '300MPA',
                    "300+\s+MPA",
                ],
            ],
            //GR350
            [
                'gradeEnum' => GradeEnums::GR350,
                'regex' => [
                    'Mild',
                    'MS',
                    'GR350',
                    "GRADE+\s+350",
                    '350MPA',
                    "350+\s+MPA",
                ],
            ],
            //GR 4.6
            [
                'gradeEnum' => GradeEnums::GR_4_6,
                'regex' => [
                    "4\.6",
                ],
            ],
            //GR 8.8
            [
                'gradeEnum' => GradeEnums::GR_8_8,
                'regex' => [
                    "8\.8",
                ],
            ],
            //GR 12.9
            [
                'gradeEnum' => GradeEnums::GR_12_9,
                'regex' => [
                    "12\.9",
                ],
            ],
            //todo more
        ];

        foreach ($grades as $grade) {
            foreach ($grade['regex'] as $pattern) {
                $regex = '/'.$pattern.'/i';
                if (preg_match($regex, $text)) {
                    $gradeResults[] = $grade['gradeEnum'];
                }
            }
        }

        /**
         * Default grade
         */
        //        if(!$gradeResults){
        //            $gradeResults = [
        //                $productConfig["defaultGrade"],
        //            ];
        //        }

        return $gradeResults;
    }

    public function findSurface($productConfig, $text): ?SurfaceEnums
    {
        /**
         * Single purpose: extract a 'surface' from text. e.g "Painted"
         */
        $surfaceResult = null;

        $surfaces = [
            [
                'surfaceEnum' => SurfaceEnums::NONE,
                'regex' => [
                    'black',
                ],
            ],
            [
                'surfaceEnum' => SurfaceEnums::PAINTED,
                'regex' => [
                    'painted',
                ],
            ],
            [
                'surfaceEnum' => SurfaceEnums::GALVANISED,
                'regex' => [
                    'galvanised',
                    'galvanise',
                    'galvanized',
                    'galvanize',
                    'gal',
                    'galv',
                ],
            ],
            [
                'surfaceEnum' => SurfaceEnums::PASSIVATED,
                'regex' => [
                    'passivated',
                ],
            ],
            [
                'surfaceEnum' => SurfaceEnums::TREATED_H2,
                'regex' => [
                    'h2',
                ],
            ],
            [
                'surfaceEnum' => SurfaceEnums::TREATED,
                'regex' => [
                    'treated',
                ],
            ],
            //todo more
        ];

        foreach ($surfaces as $surface) {
            foreach ($surface['regex'] as $pattern) {
                $regex = '/'.$pattern.'/i';
                if (preg_match($regex, $text)) {
                    $surfaceResult = $surface['surfaceEnum'];
                }
            }
        }

        /**
         * Default surface
         */
        //        if(!$surfaceResult){
        //            $surfaceResult = SurfaceEnums::NONE;
        //        }

        return $surfaceResult;
    }

    /**
     * @deprecated
     */
    public function findMeasurementUnit($productConfig): MeasurementUnitEnums
    {
        /**
         * Single purpose: get the measurement units from the product
         */

        return $productConfig['measurementUnit'] ?? MeasurementUnitEnums::SINGLE;
    }

    public function findNumberByRegex(array $productConfig, string $text, string $regexLabel): ?float
    {
        /**
         * Single purpose: extracts the number from string. e.g "200" from "200PFC"
         */
        $resultFloat = null;

        //Special condition for EA
        if ($productConfig['productCategory'] === ProductEnums::EA->value) {
            if ($regexLabel === 'nominalWidthRegex') {
                $resultFloat = $this->findEaWidth($text);
            }
            if ($regexLabel === 'nominalHeightRegex') {
                $resultFloat = $this->findEaHeight($text);
            }
            if ($regexLabel === 'wallRegex') {
                $resultFloat = $this->findEaThickness($text);
            }
        }
        //Special condition for UA
        elseif ($productConfig['productCategory'] === ProductEnums::UA->value) {
            if ($regexLabel === 'nominalWidthRegex') {
                $resultFloat = $this->findUaWidth($text);
            }
            if ($regexLabel === 'nominalHeightRegex') {
                $resultFloat = $this->findUaHeight($text);
            }
            if ($regexLabel === 'wallRegex') {
                $resultFloat = $this->findUaThickness($text);
            }
        }
        //Special condition for RHS
        elseif ($productConfig['productCategory'] === ProductEnums::RHS->value) {
            if ($regexLabel === 'nominalWidthRegex') {
                $resultFloat = $this->findRhsWidth($text);
            }
            if ($regexLabel === 'nominalHeightRegex') {
                $resultFloat = $this->findRhsHeight($text);
            }
            if ($regexLabel === 'wallRegex') {
                $resultFloat = $this->findRhsThickness($text);
            }
        }
        //All other products
        else {
            $regexPatterns = $productConfig[$regexLabel];

            foreach ($regexPatterns as $pattern) {
                $regex = '/'.$pattern.'/i';

                preg_match_all($regex, $text, $matches);

                if (! empty($matches[0][0])) {
                    preg_match_all('/-?\d+(\.\d+)?/i', $matches[0][0], $matches);
                    if (! empty($matches[0][0])) {
                        $resultFloat = (float) $matches[0][0];
                    }
                }
            }
        }

        return $resultFloat;
    }

    private function extractNumbersInAscendingOrder(string $text): array
    {
        // Extract all numbers
        preg_match_all('/\d+(\.\d+)?/', $text, $matches);

        // Convert to integers and sort numerically
        $numbersArray = array_map('floatval', $matches[0]);
        sort($numbersArray);

        return $numbersArray;
    }

    private function findEaWidth(string $text): ?float
    {
        /**
         * Width is the biggest number
         */
        $width = null;

        $numbersInAscendingOrder = $this->extractNumbersInAscendingOrder($text);
        if (count($numbersInAscendingOrder) >= 2) {
            $width = (float) max($numbersInAscendingOrder);
        }

        return $width;
    }

    private function findEaHeight(string $text): ?float
    {
        /**
         * Height is the biggest number
         */
        $height = null;

        $numbersInAscendingOrder = $this->extractNumbersInAscendingOrder($text);
        if (count($numbersInAscendingOrder) >= 2) {
            $height = (float) max($numbersInAscendingOrder);
        }

        return $height;
    }

    private function findEaThickness(string $text): ?float
    {
        /**
         * Thickness is the smallest number <= 26
         */
        $thickness = null;

        $numbersInAscendingOrder = $this->extractNumbersInAscendingOrder($text);
        if (count($numbersInAscendingOrder) >= 2) {
            $smallest = min($numbersInAscendingOrder);
            if ($smallest <= 26) {
                $thickness = (float) $smallest;
            }
        }

        return $thickness;
    }

    private function findUaWidth(string $text): ?float
    {
        /**
         * Width is the middle number
         */
        $width = null;

        $numbersInAscendingOrder = $this->extractNumbersInAscendingOrder($text);
        if (count($numbersInAscendingOrder) === 3) {
            $width = (float) $numbersInAscendingOrder[1];
        }

        return $width;
    }

    private function findUaHeight(string $text): ?float
    {
        /**
         * Height is the biggest number
         */
        $height = null;

        $numbersInAscendingOrder = $this->extractNumbersInAscendingOrder($text);
        if (count($numbersInAscendingOrder) >= 2) {
            $height = (float) max($numbersInAscendingOrder);
        }

        return $height;
    }

    private function findUaThickness(string $text): ?float
    {
        /**
         * Thickness is the smallest number <= 26
         */
        $thickness = null;

        $numbersInAscendingOrder = $this->extractNumbersInAscendingOrder($text);
        if (count($numbersInAscendingOrder) >= 2) {
            $smallest = min($numbersInAscendingOrder);
            if ($smallest <= 26) {
                $thickness = (float) $smallest;
            }
        }

        return $thickness;
    }

    private function findRhsWidth(string $text): ?float
    {
        /**
         * Width is the middle number
         */
        $width = null;

        $numbersInAscendingOrder = $this->extractNumbersInAscendingOrder($text);

        if (count($numbersInAscendingOrder) === 3) {
            $width = (float) $numbersInAscendingOrder[1];
        }

        return $width;
    }

    private function findRhsHeight(string $text): ?float
    {
        /**
         * Height is the biggest number
         */
        $height = null;

        $numbersInAscendingOrder = $this->extractNumbersInAscendingOrder($text);
        if (count($numbersInAscendingOrder) >= 2) {
            $height = (float) max($numbersInAscendingOrder);
        }

        return $height;
    }

    private function findRhsThickness(string $text): ?float
    {
        /**
         * Thickness is the smallest number <= 16
         */
        $thickness = null;

        $numbersInAscendingOrder = $this->extractNumbersInAscendingOrder($text);
        if (count($numbersInAscendingOrder) >= 2) {
            $smallest = min($numbersInAscendingOrder);
            if ($smallest <= 16) {
                $thickness = (float) $smallest;
            }
        }

        return $thickness;
    }
}
