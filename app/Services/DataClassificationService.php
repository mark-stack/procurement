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
    private const MM_PER_INCH = 25.4;

    /**
     * The platform plate catalogue runs 5mm to 150mm. Above that is not a thickness, it is a
     * dimension that a pattern reached for when the thickness was not where it expected one.
     */
    private const MAX_PLATE_THICKNESS_MM = 150.0;

    /**
     * The most numbers one dimension group can hold: depth, width and thickness. A run
     * longer than this reached across an "x" into something that is not a dimension.
     */
    private const DIMENSION_GROUP_MAX = 3;

    /**
     * No stocked angle leg is thicker than 26mm and no stocked hollow section wall is
     * thicker than 16mm. A smallest-number-in-the-group above these is not a thickness,
     * it is a dimension reached for when the thickness was never written down.
     */
    private const MAX_ANGLE_THICKNESS_MM = 26.0;

    private const MAX_WALL_THICKNESS_MM = 16.0;

    /**
     * What a match against a number is worth when categories compete - see
     * scoreConfigMatch(). Larger than any descriptor so a dimensioned match always
     * outranks a bare keyword, and length only separates matches level on it.
     */
    private const SCORE_DIMENSIONED = 1000;

    /**
     * What it costs to be the catch-all fastener category - enough that any category which
     * names itself outranks it, while still leaving it ahead of no match at all.
     */
    private const SCORE_DEFAULT_FASTENER_PENALTY = 1000;

    /**
     * Which attribute each regex list is asking for, so an ImperialSectionReader result can
     * answer the same question the patterns would have.
     */
    private const IMPERIAL_ATTRIBUTE_KEYS = [
        'nominalHeightRegex' => 'nominal_height',
        'nominalWidthRegex' => 'nominal_width',
        'nominalLengthRegex' => 'nominal_length',
        'wallRegex' => 'wall',
        'weightRegex' => 'kg_per_m',
    ];

    /**
     * The tokens each positional category writes its profile as, most specific first. They
     * locate the dimension group in a descriptor that holds more than one run of numbers -
     * see findDimensionGroup(). "L" is the AISC angle prefix, "A" the Advance Steel one;
     * "HSS" and "TS" cover square and rectangular tube with one token each.
     */
    private const EA_TOKENS = ['EA', 'L', 'A'];

    private const UA_TOKENS = ['UA', 'L', 'A'];

    private const RHS_TOKENS = ['RHS', 'HSS', 'TS'];

    private const SHS_TOKENS = ['SHS', 'HSS', 'TS'];

    /**
     * Manufacturers' Standard Gauge for sheet steel, in millimeters. The number in "16GA" is a
     * position on this table, not a measurement - the two run in opposite directions, so a
     * bigger gauge is thinner sheet.
     */
    private const SHEET_GAUGE_MM = [
        3 => 6.073,
        4 => 5.695,
        5 => 5.314,
        6 => 4.935,
        7 => 4.554,
        8 => 4.176,
        9 => 3.797,
        10 => 3.416,
        11 => 3.038,
        12 => 2.657,
        13 => 2.278,
        14 => 1.897,
        15 => 1.709,
        16 => 1.519,
        17 => 1.367,
        18 => 1.214,
        19 => 1.062,
        20 => 0.912,
        21 => 0.836,
        22 => 0.759,
        23 => 0.682,
        24 => 0.607,
        25 => 0.531,
        26 => 0.455,
        27 => 0.417,
        28 => 0.378,
    ];

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
                /*
                 * Otherwise assume it's nominal.
                 *
                 * This matched kg_per_m against the HEIGHT, which could only ever return
                 * nothing - no 310UB masses 310kg/m. It stayed hidden because the UB and UC
                 * equivalence matrices cover most stocked masses, so this branch is only
                 * reached by a mass they are missing: 180UB16.1 queried kg_per_m = 180 and
                 * matched no product at all.
                 */
                else {
                    $query->where('kg_per_m', $kg_per_m);
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

                /*
                 * Collected inside the loop. This sat outside it, against a variable the
                 * next category overwrote, so a description carrying custom products in
                 * more than one category kept only the last category's - every earlier
                 * one was dropped without a trace.
                 */
                foreach ($resultsThisProductCategory as $result) {
                    $results[] = $result;
                }
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
            [16, 16.1, 16], //180UB16.1 - the one stocked mass this matrix was missing
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
         *
         * Fasteners and sections are scored TOGETHER, and the most specific match wins -
         * see scoreConfigMatch(). Two older rules used to decide this instead, and between
         * them they sent plainly-readable descriptors to the wrong category:
         *
         * - the fastener pass ran first and claimed the row outright, so a section that
         *   merely mentioned a fastener went to the fastener ("150PFC GALV NUT PLATE" -> NUT,
         *   "310UB40 HD BOLT CLEAT" -> ANCHOR_STUD);
         * - whatever survived was picked with $resultProductConfigs[0], and the configs
         *   arrive in filename order, so ties were settled alphabetically by class name.
         */
        if ($text === null || trim($text) === '') {
            return null;
        }

        $productService = new ProductService;

        $candidates = array_merge(
            $productService->getProductConfigs(true),
            $productService->getProductConfigs(false),
        );

        //Both hold for the whole descriptor, so they are settled once rather than per category
        $fastenersFound = $this->fastenersFoundInText($text);
        $threadDesignation = $this->threadDesignationInText($text);

        $best = null;
        $bestScore = null;

        foreach ($candidates as $candidate) {
            $config = $candidate['config'];

            //negative keywords
            $containsNegativeKeywords = false;
            foreach ($config['negativeKeywords'] as $negativeKeyword) {
                if ($this->containsSubstring($text, $negativeKeyword)) {
                    $containsNegativeKeywords = true;
                }
            }

            if ($containsNegativeKeywords) {
                continue;
            }

            /*
             * A fastener category only competes once the text actually reads like a
             * fastener - "M16", a bolt, a nut, a washer. Without that gate the bare word
             * "nut" in a section descriptor is a candidate on equal footing.
             */
            if ($config['isFastener'] && ! $fastenersFound) {
                continue;
            }

            $score = $this->scoreConfigMatch($config, $text, $threadDesignation);

            if ($score === null) {
                continue;
            }

            if ($bestScore === null || $score > $bestScore) {
                $best = $config;
                $bestScore = $score;
            }
        }

        /*
         * A fastener term with no category of its own is a hex bolt. This is the long-standing
         * default, and it stays LAST so that a section match beats it rather than the other
         * way around.
         */
        if ($best === null && $fastenersFound) {
            $best = $this->findDefaultFastenerConfig();
        }

        return $best;
    }

    private function scoreConfigMatch(array $config, string $text, bool $threadDesignation): ?int
    {
        /**
         * Single purpose: how specifically does this category's own notation describe this
         * text? Null means it does not match at all.
         *
         * A BOM line names its material with a dimensioned designation - "150PFC", "310UB40",
         * "PL10", "100x100x10 EA". A bare keyword somewhere in the line ("nut", "plate",
         * "bolt") describes a feature of the part, not the material being bought. So a match
         * sitting against a number outranks one that is not, however long either is, and
         * length only separates matches that are level on that.
         */
        $bestScore = null;

        foreach ($config['productRegex'] as $pattern) {
            $regex = '/'.$pattern.'/i';

            if (preg_match($regex, $text, $matches, PREG_OFFSET_CAPTURE) !== 1) {
                continue;
            }

            [$match, $offset] = $matches[0];

            $before = $offset > 0 ? substr($text, $offset - 1, 1) : '';
            $after = substr($text, $offset + strlen($match), 1);

            /*
             * A fastener names its size as a thread - "M20" - and a thread designation
             * dimensions the whole descriptor, wherever in it the fastener noun sits. Without
             * that, "M20x500 D20 ANCHOR ROD" scored an undimensioned "anchor rod" against a
             * dimensioned "D20" and came back as round bar.
             */
            $dimensioned = preg_match('/\d/', $match) === 1
                || ctype_digit($before)
                || ctype_digit($after)
                || ($config['isFastener'] && $threadDesignation);

            /*
             * HEX_BOLT is the catch-all every unnamed fastener falls back to, so it has to
             * lose to any category that names itself - "M12 CSK BOLT" is a countersunk bolt,
             * and on word length alone "bolt" would beat "csk". This is the long-standing
             * "all fastener categories take priority over HEX_BOLT" rule, kept.
             */
            $isDefaultFastener = $config['productCategory'] === ProductEnums::HEX_BOLT->value;

            $score = ($dimensioned ? self::SCORE_DIMENSIONED : 0)
                + strlen($match)
                - ($isDefaultFastener ? self::SCORE_DEFAULT_FASTENER_PENALTY : 0);

            if ($bestScore === null || $score > $bestScore) {
                $bestScore = $score;
            }
        }

        return $bestScore;
    }

    private function threadDesignationInText(string $text): bool
    {
        /**
         * Single purpose: is there an ISO metric thread in this text? "M20", "M12x100".
         * Nothing but a fastener is specified that way, which is what makes it decisive.
         */
        return preg_match('/\bM\d+/i', $text) === 1;
    }

    private function findDefaultFastenerConfig(): ?array
    {
        foreach ((new ProductService)->getProductConfigs(true) as $fastenerConfig) {
            if ($fastenerConfig['config']['productCategory'] === ProductEnums::HEX_BOLT->value) {
                return $fastenerConfig['config'];
            }
        }

        return null;
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
                    "hdg",
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
            [
                'surfaceEnum' => SurfaceEnums::ZINC,
                'regex' => [
                    'zinc',
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

        /*
         * An American designation carries its dimensions in inches, and no regex multiplies,
         * so it is converted before the patterns get a look - the same shape as the plate
         * notations, which settle gauge and imperial ahead of the shared metric patterns.
         */
        $imperial = (new ImperialSectionReader)->attributes($text, $productConfig['productCategory']);

        if ($imperial !== []) {
            return $imperial[self::IMPERIAL_ATTRIBUTE_KEYS[$regexLabel] ?? ''] ?? null;
        }

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
        //Special condition for SHS
        elseif ($productConfig['productCategory'] === ProductEnums::SHS->value) {
            if ($regexLabel === 'nominalWidthRegex') {
                $resultFloat = $this->findShsWidth($text);
            }
            if ($regexLabel === 'nominalHeightRegex') {
                $resultFloat = $this->findShsHeight($text);
            }
            if ($regexLabel === 'wallRegex') {
                $resultFloat = $this->findShsThickness($text);
            }
        }
        //Special condition for PLATE
        elseif ($productConfig['productCategory'] === ProductEnums::PLATE->value) {
            if ($regexLabel === 'nominalHeightRegex') {
                $resultFloat = $this->findPlateThickness($text, $productConfig[$regexLabel]);
            } else {
                $resultFloat = $this->findNumberByRegexPatterns($productConfig[$regexLabel], $text);
            }
        }
        //All other products
        else {
            $resultFloat = $this->findNumberByRegexPatterns($productConfig[$regexLabel], $text);
        }

        return $resultFloat;
    }

    private function findNumberByRegexPatterns(array $regexPatterns, string $text): ?float
    {
        /**
         * Single purpose: walk a category's patterns and pull the number out of whichever
         * one matched.
         *
         * The loop does not stop at the first match, so the LAST pattern to match wins.
         * That is why the order a category declares its patterns in matters, and why a
         * new pattern appended to a list takes precedence over the ones above it.
         *
         * The number is the first one anywhere in the match, UNLESS the pattern names the
         * group it wants with "(?<num>...)". A pattern cannot opt in by accident, which is
         * what matters here: most of these patterns have a capture group that is only a
         * fragment of the number - group 1 of "CHS+\d+(\.\d+)?" is ".7", not "193.7" - so
         * reading group 1 by position would quietly turn 193.7mm pipe into 0.7mm.
         *
         * The named group is what makes the three-number metric forms readable. The mass in
         * "UB 310x165x40" is the third number, not the first, and no amount of pattern
         * ordering lets "the first number in the match" reach it.
         */
        $resultFloat = null;

        foreach ($regexPatterns as $pattern) {
            $regex = '/'.$pattern.'/i';

            if (preg_match_all($regex, $text, $matches) < 1) {
                continue;
            }

            //The number the pattern asked for by name
            if (isset($matches['num'][0]) && $matches['num'][0] !== '') {
                $resultFloat = (float) $matches['num'][0];

                continue;
            }

            //Otherwise the first number anywhere in the match
            if (! empty($matches[0][0])) {
                preg_match_all('/-?\d+(\.\d+)?/i', $matches[0][0], $numbers);
                if (! empty($numbers[0][0])) {
                    $resultFloat = (float) $numbers[0][0];
                }
            }
        }

        return $resultFloat;
    }

    private function findPlateThickness(string $text, array $metricPatterns): ?float
    {
        /**
         * Single purpose: the plate thickness in millimeters, however the detailer wrote it.
         *
         * Thickness is the one attribute findGeneralProductMatches() joins a plate on, so
         * every notation has to reduce to the same millimeter number. Three of them cannot
         * be read by the shared patterns at all, and each has to be settled before them:
         * a gauge and an imperial fraction both OPEN with a number that is not a thickness,
         * and the leading-PL form separates on a hyphen the shared number extraction reads
         * as a minus sign. Order matters - "PL16GAx15 1/2" is a gauge AND a fraction, and
         * the gauge is the thickness.
         */
        return $this->findPlateGaugeThickness($text)
            ?? $this->findPlateImperialThickness($text)
            ?? $this->findPlateLeadingThickness($text)
            ?? $this->findNumberByRegexPatterns($metricPatterns, $text);
    }

    private function findPlateGaugeThickness(string $text): ?float
    {
        /**
         * Gauge is an American sheet notation and arrives from SDS2 - "PL16GAx15 1/2",
         * "PL 10GA x 48 x 96". The leading number is a gauge, not a thickness: reading it
         * as millimeters makes 16mm plate out of 1.5mm sheet.
         *
         * What has to be ruled out is "PL10 GALV", where GA opens a longer word. A word
         * boundary will not do it on its own - there is no boundary in "16GAx15" either,
         * because the dimension separator is itself a letter. Hence two patterns: GA ending
         * the token, and GA against an "x" or "*" that a dimension follows.
         */
        $gaugePatterns = [
            '/(\d+)\s?GA(?![A-Za-z])/i',   //PL 10GA, PL 10GA x 48 x 96
            '/(\d+)\s?GA\s?[x*]\s?\d/i',   //PL16GAx15 1/2
        ];

        foreach ($gaugePatterns as $pattern) {
            if (preg_match($pattern, $text, $matches) === 1) {
                return self::SHEET_GAUGE_MM[(int) $matches[1]] ?? null;
            }
        }

        return null;
    }

    private function findPlateImperialThickness(string $text): ?float
    {
        /**
         * Imperial plate is written thickness-first in every notation that reaches here -
         * "PL1/2*4*8", "PL3/8x1-0", "1/4\" x 4' x 8' PL" - because thickness is what the
         * plate is named for.
         *
         * A fraction is only read as imperial when it sits against the plate token or opens
         * the descriptor. Without that rule "10mm / 500 / 1000" reads its own flat extents
         * as the fraction 500/1000 and calls a 10mm plate half an inch thick.
         */
        $inches = null;

        //Fraction against the plate token, or opening the descriptor: PL3/8, PL 1 1/2, 1/4" x 4'
        if (preg_match('/(?:\bPL\s?|^)(?:(\d+)\s+)?(\d+)\s?\/\s?(\d+)/i', $text, $matches) === 1) {
            $denominator = (float) $matches[3];

            if ($denominator > 0.0) {
                $inches = (float) ($matches[1] ?: 0) + ((float) $matches[2] / $denominator);
            }
        }

        //An inch mark is the only thing separating inches from millimeters: PL 1/2" x 48" x 96"
        if ($inches === null && preg_match('/(\d+(?:\.\d+)?)\s?"/', $text, $matches) === 1) {
            $inches = (float) $matches[1];
        }

        if ($inches === null) {
            return null;
        }

        $millimeters = $inches * self::MM_PER_INCH;

        /**
         * Both patterns above can reach a neighbouring dimension when the thickness is not
         * where the notation says it is - the width in "PL16GAx15 1/2" is 15 1/2 inches,
         * which converts to 393mm of plate. The catalogue stops at 150mm, so anything above
         * that was not a thickness. Returning null leaves the row matched against every
         * plate rather than one wrong one, which is the user's call to make.
         */
        if ($millimeters <= 0.0 || $millimeters > self::MAX_PLATE_THICKNESS_MM) {
            return null;
        }

        return $millimeters;
    }

    private function findPlateLeadingThickness(string $text): ?float
    {
        /**
         * "PL10", "PL 10", "PL10*500*1000", "PL-10-500-1000", "S355 PL10" - the thickness
         * follows the token rather than leading it, which is how Tekla, Advance Steel, SDS2
         * and most generic fabrication exports write plate.
         *
         * Read off the capture group rather than the whole match: the separator in
         * "PL-10-500-1000" is a hyphen, and findNumberByRegexPatterns() reads that as the
         * sign and returns -10.
         */
        if (preg_match('/\bPL[-\s]?(\d+(?:\.\d+)?)/i', $text, $matches) !== 1) {
            return null;
        }

        return (float) $matches[1];
    }

    private function findDimensionGroup(string $text, array $tokens): array
    {
        /**
         * Single purpose: the numbers a detailer wrote as ONE dimension group, ascending -
         * the "150x100x6" in "150x100x6 RHS GR350 8000".
         *
         * The positional extractors below read a section's depth, width and thickness off
         * the relative size of these numbers. That only holds while every number IS a
         * dimension, and they used to be handed every number in the descriptor, so a grade,
         * a length or a quantity sharing the cell became the section's own size:
         * "250x150x9 RHS x 12000" was a 12000mm-deep RHS, "150x100x6 RHS GR350" a 350mm-deep
         * one, and "100x100x10 EA 9000" a 9000mm angle. None of the three matched anything,
         * so the row was reported as not found with no sign of what had gone wrong.
         *
         * A group is numbers joined by "x", "*" or "×". Where a descriptor holds more than
         * one group, or one group runs longer than a section has dimensions, the numbers
         * nearest the profile token are the section's - that is what separates the
         * "150x100x6" from the "x 12000" trailing it.
         */

        //"×" is what Excel autocorrect makes of "x". Normalised first so offsets below agree.
        $text = str_replace('×', 'x', $text);

        if (preg_match_all('/\d+(?:\.\d+)?(?:\s*[x*]\s*\d+(?:\.\d+)?)+/i', $text, $matches, PREG_OFFSET_CAPTURE) < 1) {
            return $this->findTokenFlankedNumbers($text, $tokens);
        }

        $tokenOffset = $this->findTokenOffset($text, $tokens);

        //The group nearest the profile token
        $group = null;
        $shortestDistance = null;
        foreach ($matches[0] as [$candidate, $offset]) {
            $distance = $tokenOffset === null
                ? 0
                : min(abs($tokenOffset - $offset), abs($tokenOffset - ($offset + strlen($candidate))));

            if ($shortestDistance === null || $distance < $shortestDistance) {
                $group = ['text' => $candidate, 'offset' => $offset];
                $shortestDistance = $distance;
            }
        }

        preg_match_all('/\d+(?:\.\d+)?/', $group['text'], $found);
        $numbers = array_map('floatval', $found[0]);

        /*
         * A group with more numbers than the section has dimensions picked up a neighbour
         * across an "x" - a length, most often. Keep the end of the run the token is on.
         */
        if (count($numbers) > self::DIMENSION_GROUP_MAX) {
            $numbers = ($tokenOffset !== null && $tokenOffset > $group['offset'])
                ? array_slice($numbers, -self::DIMENSION_GROUP_MAX)
                : array_slice($numbers, 0, self::DIMENSION_GROUP_MAX);
        }

        sort($numbers);

        return $numbers;
    }

    private function findTokenFlankedNumbers(string $text, array $tokens): array
    {
        /**
         * Single purpose: the numbers either side of the profile token, ascending, for the
         * forms that write no dimension group at all - "65 SHS 2.5", "100 SHS 5.0". The
         * size leads the token and the wall trails it.
         */
        foreach ($tokens as $token) {
            $pattern = '/(\d+(?:\.\d+)?)\s*'.preg_quote($token, '/').'\s*(\d+(?:\.\d+)?)/i';

            if (preg_match($pattern, $text, $matches) === 1) {
                $numbers = [(float) $matches[1], (float) $matches[2]];
                sort($numbers);

                return $numbers;
            }
        }

        return [];
    }

    private function findTokenOffset(string $text, array $tokens): ?int
    {
        foreach ($tokens as $token) {
            $offset = stripos($text, $token);

            if ($offset !== false) {
                return $offset;
            }
        }

        return null;
    }

    private function findEaWidth(string $text): ?float
    {
        /**
         * An equal angle's legs are equal, so width is the biggest number in the group
         */
        $group = $this->findDimensionGroup($text, self::EA_TOKENS);

        return count($group) >= 2 ? (float) max($group) : null;
    }

    private function findEaHeight(string $text): ?float
    {
        /**
         * Height is the biggest number in the group
         */
        $group = $this->findDimensionGroup($text, self::EA_TOKENS);

        return count($group) >= 2 ? (float) max($group) : null;
    }

    private function findEaThickness(string $text): ?float
    {
        /**
         * Thickness is the smallest number in the group, and no angle leg is thicker than 26
         */
        return $this->smallestWithinThickness($this->findDimensionGroup($text, self::EA_TOKENS), self::MAX_ANGLE_THICKNESS_MM);
    }

    private function findUaWidth(string $text): ?float
    {
        /**
         * The short leg - the middle number of the three
         */
        $group = $this->findDimensionGroup($text, self::UA_TOKENS);

        return count($group) === 3 ? (float) $group[1] : null;
    }

    private function findUaHeight(string $text): ?float
    {
        /**
         * The long leg - the biggest number in the group
         */
        $group = $this->findDimensionGroup($text, self::UA_TOKENS);

        return count($group) >= 2 ? (float) max($group) : null;
    }

    private function findUaThickness(string $text): ?float
    {
        /**
         * Thickness is the smallest number in the group, and no angle leg is thicker than 26
         */
        return $this->smallestWithinThickness($this->findDimensionGroup($text, self::UA_TOKENS), self::MAX_ANGLE_THICKNESS_MM);
    }

    private function findRhsWidth(string $text): ?float
    {
        /**
         * Width is the middle number of the three
         */
        $group = $this->findDimensionGroup($text, self::RHS_TOKENS);

        return count($group) === 3 ? (float) $group[1] : null;
    }

    private function findRhsHeight(string $text): ?float
    {
        /**
         * Height is the biggest number in the group
         */
        $group = $this->findDimensionGroup($text, self::RHS_TOKENS);

        return count($group) >= 2 ? (float) max($group) : null;
    }

    private function findRhsThickness(string $text): ?float
    {
        /**
         * Wall is the smallest number in the group, and no hollow section wall exceeds 16
         */
        return $this->smallestWithinThickness($this->findDimensionGroup($text, self::RHS_TOKENS), self::MAX_WALL_THICKNESS_MM);
    }

    private function findShsWidth(string $text): ?float
    {
        /**
         * A square hollow section is square, so width is its one across-flats size
         */
        return $this->findShsSize($text);
    }

    private function findShsHeight(string $text): ?float
    {
        /**
         * A square hollow section is square, so height is its one across-flats size
         */
        return $this->findShsSize($text);
    }

    private function findShsSize(string $text): ?float
    {
        /**
         * Both SHS dimensions are the same number, and it is the biggest in the group -
         * written either as a full group ("100x100x5") or as size-token-wall ("65 SHS 2.5").
         */
        $group = $this->findDimensionGroup($text, self::SHS_TOKENS);

        return count($group) >= 2 ? (float) max($group) : null;
    }

    private function findShsThickness(string $text): ?float
    {
        /**
         * Wall is the smallest number in the group, and no hollow section wall exceeds 16
         */
        return $this->smallestWithinThickness($this->findDimensionGroup($text, self::SHS_TOKENS), self::MAX_WALL_THICKNESS_MM);
    }

    private function smallestWithinThickness(array $group, float $maximum): ?float
    {
        /**
         * Single purpose: the smallest number in a dimension group, but only while it is
         * small enough to BE a thickness.
         *
         * Returning null for anything larger is deliberate: a group of two equal numbers
         * ("100 x 100mm EA") has no thickness in it, and a null leaves the row matched
         * against every thickness in the size rather than against one wrong one.
         */
        if (count($group) < 2) {
            return null;
        }

        $smallest = min($group);

        return $smallest <= $maximum ? (float) $smallest : null;
    }
}
