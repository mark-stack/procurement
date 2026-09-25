<?php

namespace App\Services;

use App\Enums\MeasurementUnitEnums;
use App\Enums\ProductEnums;
use App\Models\Business;
use App\Models\Product;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;
use ReflectionClass;

class ProductService
{
    public function getProductConfigs(bool $fasteners): array
    {
        $productConfigs = [];

        $implementations = $this->getImplementations();

        foreach ($implementations as $implementation) {
            // Check if the class exists
            if (class_exists($implementation)) {
                $service = new $implementation;
                $config = $service->config();

                //Fasteners
                if ($config['isFastener'] === $fasteners) {
                    $productConfigs[] = [
                        'config' => $config,
                        'service' => $service,
                    ];
                } else {
                    $productConfigs[] = [
                        'config' => $config,
                        'service' => $service,
                    ];
                }
            }
        }

        return $productConfigs;
    }

    public function getCertificateFromProductCategory(?string $productCategory): bool
    {
        $certificate = false;

        //Has product category
        if ($productCategory) {
            //A deprecated product is not a source of truth for a live category
            $product = Product::query()
                ->active()
                ->where('product_category', $productCategory)
                ->first();

            $certificate = $product
                ? (bool) $product->certificates
                : false;
        }

        return $certificate;
    }

    public function senseChecks($materialListRows, $productCategories, $hasCertificateProducts): array
    {
        $senseChecks = [];

        if (count($materialListRows) > 0) {
            /**
             * Has bolts?
             */
            $senseChecks['has_bolts'] = false;
            if (in_array(ProductEnums::HEX_BOLT->value, $productCategories)) {
                $senseChecks['has_bolts'] = true;
            }

            /**
             * Bolt qty: is it low?
             */
            $senseChecks['bolt_qty'] = 1000; //todo
            //todo complete

            /**
             * Material Certificates //todo: get from master_materials
             */
            $senseChecks['certificates'] = false;
            if ($hasCertificateProducts) {
                $senseChecks['certificates'] = true;
            }
        }

        return $senseChecks;
    }

    public function getProductMatchOptions(Business $business, object $rawMaterialQuote): ?array
    {
        /**
         * Sort the user's material rows into groups:
         * 1) Non-price book (will be user custom product)
         * 2) Price book exact match
         * 3) price book partial match (requires confirmation)
         */
        $result = null;

        /**
         * Decoded general products
         * Add derived product label to every option. e.g "200PFC SS316"
         */
        $decodedGeneralProductsRaw = unserialize($rawMaterialQuote->general_product_matches);
        if(gettype($decodedGeneralProductsRaw) === 'string'){
            $decodedGeneralProductsRaw = unserialize($decodedGeneralProductsRaw);
        }

        $decodedGeneralProducts = [];
        foreach ($decodedGeneralProductsRaw['results'] as $option) {
            $option['product_derived_label'] = $this->getDerivedProductLabel($option);
            $decodedGeneralProducts[] = $option;
        }

        /**
         * Decoded custom products
         * Add derived product label to every option. e.g "200PFC SS316"
         */
        $decodedCustomProductsRaw = unserialize($rawMaterialQuote->custom_product_matches);
        $decodedCustomProducts = [];
        if($decodedCustomProductsRaw){
            foreach ($decodedCustomProductsRaw as $option) {
                $option['product_derived_label'] = $this->getDerivedProductLabel($option);
                $decodedCustomProducts[] = $option;
            }
        }

        /**
         * 1) Non-price book (will be user custom product)
         */
        if (count($decodedCustomProducts) > 0 && ! $rawMaterialQuote->custom_confirmed) {
            $result = [
                'status' => 'PARTIAL',
                'decodedOptions' => $decodedCustomProducts,
                'custom' => true,
                'supplierGroup' => $decodedGeneralProductsRaw['supplierGroup'],
            ];
        }
        //Price book candidate
        else {
            /**
             * 2) Price book exact match
             */
            if (count($decodedGeneralProducts) === 1) {
                //Supplier group belongs to current plan
                $supplierGroup = $decodedGeneralProductsRaw['supplierGroup'];
                if ($business->supplierGroupIsCurrentPlan($supplierGroup)) {
                    $result = [
                        'status' => 'EXACT',
                        'decodedOption' => $decodedGeneralProducts[0],
                        'supplierGroup' => $decodedGeneralProductsRaw['supplierGroup'],
                    ];
                }
            }

            /**
             * 3) Price book partial match (requires confirmation)
             */
            if (count($decodedGeneralProducts) > 1) {
                $result = [
                    'status' => 'PARTIAL',
                    'decodedOptions' => $decodedGeneralProducts,
                    'custom' => false,
                    'supplierGroup' => $decodedGeneralProductsRaw['supplierGroup'],
                ];
            }
            //If no results, it's user-custom
            if (count($decodedGeneralProducts) === 0 && count($decodedCustomProducts) === 0) {
                if ($business->allow_custom_products) {
                    $result = [
                        'status' => 'CUSTOM',
                        'decodedOptions' => null,
                        'supplierGroup' => null,
                    ];
                }
            }
        }

        return $result;
    }

    public function getDerivedProductLabel(array $productSpec): string
    {
        /**
         * Convert product spec array to derived product label
         */
        $productCategory = $productSpec['product_category'];
        $nominal_length = isset($productSpec['nominal_length'])
            ? floatval($productSpec['nominal_length'])
            : null;
        $precise_length = isset($productSpec['precise_length'])
            ? floatval($productSpec['precise_length'])
            : null;
        $nominal_width = isset($productSpec['nominal_width'])
            ? floatval($productSpec['nominal_width'])
            : null;
        $precise_width = isset($productSpec['precise_width'])
            ? floatval($productSpec['precise_width'])
            : null;
        $nominal_height = isset($productSpec['nominal_height'])
            ? floatval($productSpec['nominal_height'])
            : null;
        $precise_height = isset($productSpec['precise_height'])
            ? floatval($productSpec['precise_height'])
            : null;
        $grade = $productSpec['grade'];
        $surface = $productSpec['surface'];
        $wall = $productSpec['wall'] ?? null;
        $kg_per_m = $productSpec['kg_per_m'] ?? null;
        $material = $productSpec['material'] ?? null;

        //Derived label. e.g "200PFC SS316"
        return $this->generateProductLabel(
            $productCategory,
            $nominal_length,
            $precise_length,
            $nominal_width,
            $precise_width,
            $nominal_height,
            $precise_height,
            $grade,
            $surface,
            $wall,
            $kg_per_m,
            $material,
        );
    }

    public function getNominalSizeData(): array
    {
        $getNominalSizeData = [
            'general' => [
                'length' => true,
                'width' => true,
                'height' => true,
                'length_placeholder' => 'Length (mm)',
                'width_placeholder' => 'Width (mm)',
                'height_placeholder' => 'Height (mm)',
            ],
        ];

        $implementations = (new ProductService)->getImplementations();

        foreach ($implementations as $implementation) {
            // Check if the class exists
            if (class_exists($implementation)) {
                $service = new $implementation;
                $productCategory = $service->config()['productCategory'];
                $getNominalSizeData[$productCategory] = $service->getNominalSizeData();
            }
        }

        return $getNominalSizeData;

        //        return [
        //            "SHS" => [
        //                "length" => false,
        //                "width" => false,
        //                "height" => true,
        //                "length_placeholder" => "",
        //                "width_placeholder" => "",
        //                "height_placeholder" => "Height/Width (mm)",
        //            ],
        //            "RHS" => [
        //                "length" => false,
        //                "width" => true,
        //                "height" => true,
        //                "length_placeholder" => "x",
        //                "width_placeholder" => "Width (mm)",
        //                "height_placeholder" => "Height (mm)",
        //            ],
        //        ];
    }

    //    public function senseChecks(): void
    //    {
    //        //todo
    ////        $lengthRequired = $this->senseCheckLengthRequired($lengthRequired,$measurementUnit);
    //    }

    //    public function getLengthRequired($lengthRequiredColumnIndex,$row,$description): int
    //    {
    //        $result = null;
    //
    //        /**
    //         * If has a "length required" column
    //         */
    //        if($lengthRequiredColumnIndex){
    //            //Has length in description
    //            $lengthInDescription = $this->searchLengthRequiredInDescription($description);
    //            if($lengthInDescription){
    //                $result = $lengthInDescription;
    //            }
    //            //NO length in description
    //            else{
    //                $result = $this->normaliseLengthWidthRequired($row[$lengthRequiredColumnIndex]);
    //            }
    //        }
    //        /**
    //         * NO "length required" column
    //         */
    //        else{
    //            //Search for length in description
    //            $result = $this->searchLengthRequiredInDescription($description);
    //        }
    //
    //        //default to 1
    //        if(!$result){
    //            $result = 1;
    //        }
    //
    //        return $result;
    //    }

    public function senseCheckLengthRequired($lengthRequired, $measurementUnit)
    {
        /**
         * Case #1
         * It's generally being used for meterage, but there's a case where it's being misused as 2nd sub qty.
         * e.g "PFC 6m", length required = "3", sub qty = "10". This is 6m x 3 x 10 = 180m. Not 3 x 10 = 30m
         * Clues are:
         *   - unit rate. Is it whole 6m span, or meterage rate
         *   - mentioning the size in the description. e.g "6m"
         */
        //todo

        return $lengthRequired; //todo actual
    }

    public function normaliseArrayOfLengthsToMeters(array $lengths, string $measurementUnit): array
    {
        $normalisedToMeters = [];

        //Single
        if ($measurementUnit === MeasurementUnitEnums::SINGLE->value) {
            $normalisedToMeters = $lengths;
        }
        //Millimeters
        if ($measurementUnit === MeasurementUnitEnums::MILLIMETERS->value) {
            foreach ($lengths as $length) {
                $normalisedToMeters[] = (float) $length / 1000;
            }
        }
        //Meters
        if ($measurementUnit === MeasurementUnitEnums::METERS->value) {
            $normalisedToMeters = $lengths;
        }

        return $normalisedToMeters;
    }

    //    public function searchLengthRequiredInDescription(string $description): ?int
    //    {
    //        /**
    //         * Find length required (actual, non-nested).
    //         */
    //
    //        $result = null;
    //
    //        // Regular expression to match a number followed by specific measurement units
    //        $pattern = '/\b(\d+(\.\d+)?)\s?(m|meter|meters|mm|millimeters)\b/i';
    //
    //        // Perform regex match
    //        if (preg_match($pattern, $description, $matches)) {
    //            $result = $matches[1]; // Return the number part
    //        }
    //
    //        return $result;
    //    }

    //    public function searchMeasurementUnitInDescription(string $description): string
    //    {
    //        /**
    //         * Find the measurement unit (e.g "meters", "millimeters", "single")
    //         */
    //
    //        $unitResult = null;
    //
    //        // Regex to match a number followed by a measurement unit (including space variations)
    //        $pattern = '/\b\d+(\.\d+)?\s?(m{1,2}|meter|meters|millimeter|millimeters)\b/i';
    //
    //        if (preg_match($pattern, $description, $matches)) {
    //            // Return the unit type in uppercase if matched
    //            $unit = strtolower($matches[2]);
    //            if ($unit === 'm' || $unit === 'mm') {
    //                // Handle special cases for m and mm
    //                $unitResult = $unit === 'm'
    //                    ? MeasurementUnitEnums::METERS->value
    //                    : MeasurementUnitEnums::MILLIMETERS->value;
    //            } elseif ($unit === 'meter' || $unit === 'meters') {
    //                $unitResult = MeasurementUnitEnums::METERS->value;
    //            } elseif ($unit === 'millimeter' || $unit === 'millimeters') {
    //                $unitResult = MeasurementUnitEnums::MILLIMETERS->value;
    //            }
    //        }
    //
    //        //Default if no result found yet. e.g steel sections will be METERS
    //        $defaultGroup = [
    //            "METERS" => [
    //                "PFC", "Parallel Flange Channel", "Parallel Flanged Channel",
    //                "UB", "universal beam","universal beams",
    //                "UC", "universal column","universal columns",
    //                "SHS", "square hollow section","square hollow sections",
    //                "RHS", "rectangular hollow section","rectangular hollow sections",
    //                "CHS", "circular hollow section","circular hollow sections",
    //                "UBS", "Universal Beam Section","Universal Beam Sections",
    //                "UCS", "Universal Column Section","Universal Column Sections",
    //                "HSS","Hollow Structural Section","Hollow Structural Sections",
    //                "EA", "equal angle","equal angles",
    //                "UA", "unequal angle","unequal angles",
    //                "RSJ", "rolled steel joist","rolled steel joists",
    //                "Flat Bar","Flat Bars",
    //                "round bar","round bars",
    //                "Square Bar","Square Bars",
    //                "Rebar","Reinforcement Bar","Reinforcement Bars",
    //                "I-Beam","I-Beams",
    //                "Steel Joist","Steel Joists",
    //                "Steel Column","Steel Columns",
    //                "Steel Beam","Steel Beams",
    //                "Steel Channel","Steel Channels",
    //                "Steel Angles","Steel Angles",
    //                "pipe","pipes","piping",
    //                "Tube","Tubes","tubing",
    //                "Z-Section","Z-Sections",
    //                "T-Section","T-Sections",
    //                "Corrugated",
    //            ],
    //            "MILLIMETERS" => [
    //                "Threaded Rod","Threaded Rods","allthread",
    //            ],
    //        ];
    //        if(!$unitResult){
    //            foreach ($defaultGroup as $unitTitle => $materialGroup) {
    //                foreach ($materialGroup as $keyword) {
    //                    // Use preg_match with word boundaries to avoid partial matches
    //                    $pattern = '/\b' . preg_quote($keyword, '/') . '\b/i';
    //                    if (preg_match($pattern, $description)) {
    //                        $unitResult = $unitTitle; // Return the first matching keyword
    //                    }
    //                }
    //            }
    //        }
    //
    //        //Final default = single
    //        if(!$unitResult){
    //            $unitResult = MeasurementUnitEnums::SINGLE->value;
    //        }
    //
    //        return $unitResult;
    //    }

    //    public function searchMaterialInDescription(string $description): ?string
    //    {
    //        /**
    //         * Find the material (e.g "mild steel", "stainless steel", "MDF")
    //         */
    //
    //        $allMaterials = [
    //            //Metals
    //            "MILD STEEL" => [
    //                "mild steel",
    //                "mild",
    //                "ms",
    //                "plain carbon",
    //            ],
    //            "STAINLESS STEEL" => [
    //                "stainless steel",
    //                "ss",
    //                "ss316",
    //                "ss 316",
    //                "316ss",
    //                "ss304",
    //                "ss 304",
    //                "304ss",
    //            ],
    //            "TIMBER" => [
    //                "timber",
    //                "MDF",
    //                "SPF",
    //            ],
    //        ];
    //
    //        $match = null;
    //        $words = $this->tokenizeSentence($description);
    //        foreach ($allMaterials as $materialGroupTitle => $materialGroup) {
    //            foreach($materialGroup as $material){
    //                foreach($words as $word){
    //                    if(strtoupper($word) === strtoupper($material)){
    //                        $match = $materialGroupTitle; // Return the first match
    //                        break;
    //                    }
    //                }
    //            }
    //        }
    //
    //        /**
    //         * If no initial material match, do a last minute "default" material. e.g PFC = MILD STEEL
    //         */
    //        $defaultGroups = [
    //            "MILD STEEL" => [
    //                "PFC", "Parallel Flange Channel", "Parallel Flanged Channel",
    //                "UB", "universal beam","universal beams",
    //                "UC", "universal column","universal columns",
    //                "SHS", "square hollow section","square hollow sections",
    //                "RHS", "rectangular hollow section","rectangular hollow sections",
    //                "CHS", "circular hollow section","circular hollow sections",
    //                "UBS", "Universal Beam Section","Universal Beam Sections",
    //                "UCS", "Universal Column Section","Universal Column Sections",
    //                "HSS","Hollow Structural Section","Hollow Structural Sections",
    //                "EA", "equal angle","equal angles",
    //                "UA", "unequal angle","unequal angles",
    //                "RSJ", "rolled steel joist","rolled steel joists",
    //                "Flat Bar","Flat Bars",
    //                "round bar","round bars",
    //                "Square Bar","Square Bars",
    //                "Plate","Plates",
    //                "Rebar","Reinforcement Bar","Reinforcement Bars",
    //                "Threaded Rod","Threaded Rods","allthread",
    //                "I-Beam","I-Beams",
    //                "Steel Joist","Steel Joists",
    //                "Steel Column","Steel Columns",
    //                "Steel Beam","Steel Beams",
    //                "Steel Channel","Steel Channels",
    //                "Steel Angles","Steel Angles",
    //                "Steel Tube","Steel Tubes",
    //            ],
    //            "GALVANISED" => [
    //                "bolt","bolts",
    //                "U-Bolt","U-Bolts",
    //                "washer","washers",
    //                "nut","nuts",
    //                "M6","M8","M10","M12","M14","M16","M20","M24","M30",
    //                "Threaded Rod","Threaded Rods","allthread",
    //                "purlin","purlins",
    //                "stanchion","stanchions",
    //                "Rivet","Rivets",
    //            ],
    ////            "STAINLESS STEEL" => [
    ////                //
    ////            ],
    //            "TIMBER" => [
    //                "LVL",
    //                "F17",
    //            ],
    //        ];
    //        if(!$match){
    //            foreach ($defaultGroups as $materialGroupTitle => $materialGroup) {
    //                foreach ($materialGroup as $keyword) {
    //                    // Use preg_match with word boundaries to avoid partial matches
    //                    $pattern = '/\b' . preg_quote($keyword, '/') . '\b/i';
    //                    if (preg_match($pattern, $description)) {
    //                        $match = $materialGroupTitle; // Return the first matching keyword
    //                    }
    //                }
    //            }
    //        }
    //
    //        return $match;
    //    }

    //    public function tokenizeSentence($sentence): array|false
    //    {
    //        // Use preg_split to split the sentence into words
    //        return preg_split('/\W+/', $sentence, -1, PREG_SPLIT_NO_EMPTY);
    //    }

    public function validationUserCustom(array $rows, $deletedIds): array
    {
        $validator = Validator::make([], []);
        $validationErrors = 0;
        foreach ($rows as $row) {
            $id = isset($row['data']) ? $row['data']['id'] : null;

            if ($id && ! in_array($id, $deletedIds)) {
                $product_category = $row['selected']['product_category'];
                $material = $row['selected']['material'];
                $grade = $row['selected']['grade'];
                $nominalLength = $row['selected']['nominal_length'];
                $nominalWidth = $row['selected']['nominal_width'];
                $nominalHeight = $row['selected']['nominal_height'];
                $nestingType = $row['selected']['nesting_algo'];
                $purchasable_length_1 = $row['selected']['purchasable_length_1'];
                $purchasable_length_2 = $row['selected']['purchasable_length_2'];
                $purchasable_length_3 = $row['selected']['purchasable_length_3'];
                $purchasable_width_1 = $row['selected']['purchasable_width_1'];
                $purchasable_width_2 = $row['selected']['purchasable_width_2'];
                $purchasable_width_3 = $row['selected']['purchasable_width_3'];

                //Product
                if ($product_category) {
                    if ($product_category === 'Other' && ! $row['selected_other']['product_category']) {
                        $validationErrors++;
                        $validator->errors()->add($id.'-product_category', 'product_category');
                    }
                } else {
                    $validationErrors++;
                    $validator->errors()->add($id.'-product_category', 'product_category');
                }

                //Material
                if ($material) {
                    if ($material === 'Other' && ! $row['selected_other']['material']) {
                        $validationErrors++;
                        $validator->errors()->add($id.'-material', 'material');
                    }
                } else {
                    $validationErrors++;
                    $validator->errors()->add($id.'-material', 'material');
                }

                //Grade
                if ($grade) {
                    if ($grade === 'Other' && ! $row['selected_other']['grade']) {
                        $validationErrors++;
                        $validator->errors()->add($id.'-grade', 'grade');
                    }
                } else {
                    $validationErrors++;
                    $validator->errors()->add($id.'-grade', 'grade');
                }

                /*
                 * Size
                 */
                if (isset($row['nominalSizeData'][$product_category])) {
                    $shouldHaveLength = $row['nominalSizeData'][$product_category]['length'];
                    if ($shouldHaveLength) {
                        if ($nominalLength) {
                            //Over zero
                            if ($nominalLength <= 0) {
                                $validationErrors++;
                                $validator->errors()->add($id.'-nominal_length', 'nominal_length');
                            }
                        } else {
                            $validationErrors++;
                            $validator->errors()->add($id.'-nominal_length', 'nominal_length');
                        }
                    }
                    $shouldHaveWidth = $row['nominalSizeData'][$product_category]['width'];
                    if ($shouldHaveWidth) {
                        if ($nominalWidth) {
                            //Over zero
                            if ($nominalWidth <= 0) {
                                $validationErrors++;
                                $validator->errors()->add($id.'-nominal_width', 'nominal_width');
                            }
                        } else {
                            $validationErrors++;
                            $validator->errors()->add($id.'-nominal_width', 'nominal_width');
                        }
                    }
                    $shouldHaveHeight = $row['nominalSizeData'][$product_category]['height'];
                    if ($shouldHaveHeight) {
                        if ($nominalHeight) {
                            //Over zero
                            if ($nominalHeight <= 0) {
                                $validationErrors++;
                                $validator->errors()->add($id.'-nominal_height', 'nominal_height');
                            }
                        } else {
                            $validationErrors++;
                            $validator->errors()->add($id.'-nominal_height', 'nominal_height');
                        }
                    }
                }

                //Nesting & measurement units
                if ($nestingType) {
                    /**
                     * Quantify (measurement units)
                     */
                    /*
                     * NONE (no minimum volume)
                     *  - Measurement units required: FALSE
                     *  - Size required: FALSE
                     *  - purchasable_length_1: FALSE
                     *  - purchasable_width_1: FALSE
                     */
                    if ($nestingType === 'NONE') {
                        //No actions
                    }
                    /*
                     * BUNDLE
                     * - Measurement units required: FALSE
                     * - Size required: TRUE
                     * - purchasable_length_1: TRUE
                     * - purchasable_width_1: FALSE
                     */
                    if ($nestingType === 'BUNDLE') {
                        //purchasable_length_1: TRUE
                        if ($purchasable_length_1) {
                            //Over zero
                            if ($purchasable_length_1 <= 0) {
                                $validationErrors++;
                                $validator->errors()->add($id.'-purchasable_length_1', 'purchasable_length_1');
                            }
                        } else {
                            $validationErrors++;
                            $validator->errors()->add($id.'-purchasable_length_1', 'purchasable_length_1');
                        }
                        //$purchasable_length_2 must be unique
                        if ($purchasable_length_2) {
                            //Over zero
                            if ($purchasable_length_2 <= 0) {
                                $validationErrors++;
                                $validator->errors()->add($id.'-purchasable_length_2', 'purchasable_length_2');
                            }
                            //Compare to 1
                            if ($purchasable_length_1) {
                                if ($purchasable_length_2 == $purchasable_length_1) {
                                    $validationErrors++;
                                    $validator->errors()->add($id.'-purchasable_length_2', 'purchasable_length_2');
                                }
                            }
                            //Compare to 3
                            if ($purchasable_length_3) {
                                if ($purchasable_length_2 == $purchasable_length_3) {
                                    $validationErrors++;
                                    $validator->errors()->add($id.'-purchasable_length_2', 'purchasable_length_2');
                                }
                            }
                        }
                        //$purchasable_length_3 must be unique
                        if ($purchasable_length_3) {
                            //Over zero
                            if ($purchasable_length_3 <= 0) {
                                $validationErrors++;
                                $validator->errors()->add($id.'-purchasable_length_3', 'purchasable_length_3');
                            }
                            //Compare to 2
                            if ($purchasable_length_2) {
                                if ($purchasable_length_3 == $purchasable_length_2) {
                                    $validationErrors++;
                                    $validator->errors()->add($id.'-purchasable_length_3', 'purchasable_length_3');
                                }
                            }
                            //Compare to 1
                            if ($purchasable_length_1) {
                                if ($purchasable_length_3 == $purchasable_length_1) {
                                    $validationErrors++;
                                    $validator->errors()->add($id.'-purchasable_length_3', 'purchasable_length_3');
                                }
                            }
                        }
                    }
                    /*
                     * METERAGE
                     * - Measurement units required: TRUE
                     * - purchasable_length_1: TRUE
                     * - purchasable_width_1: FALSE
                     */
                    if ($nestingType === 'METERAGE') {
                        //Measurement units required: TRUE
                        //                        if(!$measurementUnit){
                        //                            $validationErrors++;
                        //                            $validator->errors()->add($id."-quantify", 'quantify');
                        //                        }
                        //purchasable_length_1: TRUE
                        if (! $purchasable_length_1) {
                            $validationErrors++;
                            $validator->errors()->add($id.'-purchasable_length_1', 'purchasable_length_1');
                        }
                        //purchasable_length_2 must unique
                        if ($purchasable_length_2) {
                            //Compare #1
                            if ($purchasable_length_1) {
                                if ($purchasable_length_2 == $purchasable_length_1) {
                                    $validationErrors++;
                                    $validator->errors()->add($id.'-purchasable_length_2', 'purchasable_length_2');
                                }
                            }
                            //Compare #3
                            if ($purchasable_length_3) {
                                if ($purchasable_length_2 == $purchasable_length_3) {
                                    $validationErrors++;
                                    $validator->errors()->add($id.'-purchasable_length_2', 'purchasable_length_2');
                                }
                            }
                        }
                        //purchasable_length_3 must unique
                        if ($purchasable_length_3) {
                            //Compare #1
                            if ($purchasable_length_1) {
                                if ($purchasable_length_3 == $purchasable_length_1) {
                                    $validationErrors++;
                                    $validator->errors()->add($id.'-purchasable_length_3', 'purchasable_length_3');
                                }
                            }
                            //Compare #2
                            if ($purchasable_length_2) {
                                if ($purchasable_length_3 == $purchasable_length_2) {
                                    $validationErrors++;
                                    $validator->errors()->add($id.'-purchasable_length_3', 'purchasable_length_3');
                                }
                            }
                        }
                    }
                    /*
                     * AREA
                     * - Measurement units required: TRUE
                     * - Size required: FALSE
                     * - purchasable_length_1: TRUE
                     * - purchasable_width_1: TRUE
                     */
                    if ($nestingType === 'AREA') {
                        //Measurement units required: TRUE
                        //                        if(!$measurementUnit){
                        //                            $validationErrors++;
                        //                            $validator->errors()->add($id."-quantify", 'quantify');
                        //                        }
                        //purchasable_length_1: TRUE
                        if (! $purchasable_length_1) {
                            $validationErrors++;
                            $validator->errors()->add($id.'-purchasable_length_1', 'purchasable_length_1');
                        }
                        //purchasable_width_1: TRUE
                        if (! $purchasable_width_1) {
                            $validationErrors++;
                            $validator->errors()->add($id.'-purchasable_width_1', 'purchasable_width_1');
                        }
                        //Must have L and W (purchasable_length_2 && purchasable_width_2)
                        if ($purchasable_length_2 || $purchasable_width_2) {
                            if (! $purchasable_length_2) {
                                $validationErrors++;
                                $validator->errors()->add($id.'-purchasable_length_2', 'purchasable_length_2');
                            }
                            if (! $purchasable_width_2) {
                                $validationErrors++;
                                $validator->errors()->add($id.'-purchasable_width_2', 'purchasable_width_2');
                            }
                        }
                        //Must have L and W (purchasable_length_3 && purchasable_width_3)
                        if ($purchasable_length_3 || $purchasable_width_3) {
                            if (! $purchasable_length_3) {
                                $validationErrors++;
                                $validator->errors()->add($id.'-purchasable_length_3', 'purchasable_length_3');
                            }
                            if (! $purchasable_width_3) {
                                $validationErrors++;
                                $validator->errors()->add($id.'-purchasable_width_3', 'purchasable_width_3');
                            }
                        }
                        //L greater than W
                    }
                } else {
                    $validationErrors++;
                    $validator->errors()->add($id.'-nesting_algo', 'nesting_algo');
                }
            }
        }

        return [
            'validationErrors' => $validationErrors,
            'validator' => $validator,
        ];
    }

    public function getImplementationFromProductCategory(string $productCategory)
    {
        $implementationMatch = null;

        $implementations = (new ProductService)->getImplementations();
        foreach ($implementations as $implementation) {
            // Check if the class exists
            if (class_exists($implementation)) {
                $service = new $implementation;

                if (strtoupper($productCategory) === strtoupper($service->config()['productCategory'])) {
                    $implementationMatch = $service;
                }
            }
        }

        return $implementationMatch;
    }

    public function generateProductLabel(
        string $productCategory,
        ?float $nominal_length,
        ?float $precise_length,
        ?float $nominal_width,
        ?float $precise_width,
        ?float $nominal_height,
        ?float $precise_height,
        ?string $grade,
        ?string $surface,
        ?float $wall,
        ?float $kg_per_m,
        ?string $material,
    ): string {
        //Grade
        $actualGrade = $grade;
        if ($grade === 'NONE') {
            $actualGrade = '';
        }
        if ($grade === 'GR_4_6' || $grade === '4.6S') {
            $actualGrade = 'GR4.6';
        }
        if ($grade === 'GR_5_8' || $grade === '5.8S') {
            $actualGrade = 'GR5.8';
        }
        if ($grade === 'GR_8_8' || $grade === '8.8S') {
            $actualGrade = 'GR8.8';
        }
        if ($grade === 'GR_10_9' || $grade === '10.9S') {
            $actualGrade = 'GR10.9';
        }
        if ($grade === 'GR_12_9' || $grade === '12.9S') {
            $actualGrade = 'GR12.9';
        }

        //Surface
        $actualSurface = ' '.$surface;
        if ($surface === 'NONE') {
            $actualSurface = ' ';
        }
        if ($surface === 'GALVANISED') {
            $actualSurface = ' GALV';
        }
        if ($surface === 'TREATED_H2') {
            $actualSurface = ' H2';
        }

        //Loop all Product Implementations
        $implementation = $this->getImplementationFromProductCategory($productCategory);
        if ($implementation) {
            $result = $implementation->formatLabel(
                $productCategory,
                $nominal_length,
                $precise_length,
                $nominal_width,
                $precise_width,
                $nominal_height,
                $precise_height,
                $actualGrade,
                $actualSurface,
                $wall,
                $kg_per_m,
                $material,
            );
        } else {
            $result = $this->formatDefault($productCategory, $nominal_length, $nominal_width, $nominal_height, $actualGrade, $actualSurface);
        }

        return $result;
    }

    private function formatDefault($productCategory, $nominal_length, $nominal_width, $nominal_height, $actualGrade, $actualSurface): string
    {
        return $productCategory.' '.$actualGrade.$actualSurface;
    }

    public function getImplementations(): array
    {
        $namespace = 'App\Services\ProductImplementations\\';
        $path = app_path('Services/ProductImplementations');
        $exclude = 'ProductBaseImplementation';

        // Get all PHP files in the directory
        $files = File::files($path);

        $implementations = collect($files)
            ->map(function ($file) use ($namespace) {
                // Extract the class name
                $className = $namespace.pathinfo($file->getFilename(), PATHINFO_FILENAME);

                // Ensure the class exists and is not abstract
                if (class_exists($className)) {
                    $reflection = new ReflectionClass($className);

                    // Return the class name if it's not abstract
                    return ! $reflection->isAbstract() ? $className : null;
                }

                return null;
            })
            ->filter(function ($className) use ($exclude, $namespace) {
                // Exclude the specified class
                return $className !== $namespace.$exclude;
            })
            ->values()
            ->all();

        return array_filter($implementations);
    }

    public function generalProductDefinition(string $productCategory): array
    {
        //Services
        $dataClassificationService = new DataClassificationService;

        //Implementation (service)
        $implementation = $dataClassificationService->findImplementationFromProductCategory($productCategory);

        return $implementation
            ? $implementation->generalProductDefinition()
            : $dataClassificationService->fallbackGeneralProductDefinition();
    }
}
