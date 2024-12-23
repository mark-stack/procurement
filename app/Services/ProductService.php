<?php

namespace App\Services;

use App\Enums\MeasurementUnitEnums;
use App\Enums\ProductEnums;
use App\Models\Business;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;

class ProductService
{
    /**
     * @deprecated
     */
    public function findByAttributes($user, $product, $material, $grades, $surface, $measurementUnit, $size, $length): Collection
    {
        //"product" is mandatory
        if($product){
            $query = Product::query()
                ->availableFor($user)
                ->where("product", $product->value);

            if (!is_null($material)) {
                $query->where("material", $material->value);
            }

            if (!is_null($grades)) {
                $gradesArrayValues = [];
                foreach($grades as $grade){
                    $gradesArrayValues[] = $grade->value;
                }
                $query->whereIn("grade",$gradesArrayValues);
            }

            if (!is_null($surface)) {
                $query->where("surface", $surface->value);
            }

            if (!is_null($measurementUnit)) {
                $query->where("nominal_units", $measurementUnit->value);
            }

            if (!is_null($size)) {
                $query->where("size", $size);
            }

            if (!is_null($length)) {
                $query->where("length", $length);
            }

            return $query->get();
        }
        else{
            return collect([]);
        }
    }

    public function senseChecks($materialListRows,$productCategories,$hasCertificateProducts): array
    {
        $senseChecks = [];

        if(count($materialListRows) > 0){
            /**
             * Has bolts?
             */
            $senseChecks["has_bolts"] = false;
            if(in_array(ProductEnums::BOLT->value,$productCategories)){
                $senseChecks["has_bolts"] = true;
            }

            /**
             * Bolt qty: is it low?
             */
            $senseChecks["bolt_qty"] = 1000; //todo
            //todo complete

            /**
             * Material Certificates //todo: get from master_materials
             */
            $senseChecks["certificates"] = false;
            if($hasCertificateProducts){
                $senseChecks["certificates"] = true;
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

        //Prerequisite variables
        $userCustomOptions = $business->products()
            ->where("description",$rawMaterialQuote->description)
            ->get()
            ->toArray();

        /**
         * 1) Non-price book (will be user custom product)
         */
        if(count($userCustomOptions) > 0){
            //No action
        }
        //Price book candidate
        else{
            $decodedOptions = unserialize($rawMaterialQuote->general_product_matches);

            /**
             * 2) Price book exact match
             */
            if(count($decodedOptions) === 1){
                $result = [
                    "status" => "EXACT",
                    "decodedOption" => $decodedOptions[0],
                ];

                //todo debugging
//                if($result["decodedOption"]["product"] === "LVL"){
//                    dd("LVL",$result["decodedOption"]);
//                }
            }

            /**
             * 3) Price book partial match (requires confirmation)
             */
            if(count($decodedOptions) > 1){
                $result = [
                    "status" => "PARTIAL",
                    "decodedOptions" => $decodedOptions,
                ];
            }
            //If no results, it's user-custom
            if(count($decodedOptions) === 0){
                $result = [
                    "status" => "CUSTOM",
                    "decodedOptions" => null,
                ];
            }
        }

        return $result;
    }

    public function getNominalSizeData(): array
    {
        return [
            "general" => [
                "length" => true,
                "width" => true,
                "height" => true,
                "length_placeholder" => "Length (mm)",
                "width_placeholder" => "Width (mm)",
                "height_placeholder" => "Height (mm)",
            ],
            "BOLT" => [
                "length" => true,
                "width" => true,
                "height" => false,
                "length_placeholder" => "Length (mm)",
                "width_placeholder" => "Diameter (mm)",
                "height_placeholder" => "",
            ],
            "UB" => [
                "length" => false,
                "width" => false,
                "height" => true,
                "length_placeholder" => "",
                "width_placeholder" => "",
                "height_placeholder" => "Height (nominal)",
            ],
            "UC" => [
                "length" => false,
                "width" => false,
                "height" => true,
                "length_placeholder" => "",
                "width_placeholder" => "",
                "height_placeholder" => "Height (nominal)",
            ],
            "PFC" => [
                "length" => false,
                "width" => false,
                "height" => true,
                "length_placeholder" => "",
                "width_placeholder" => "",
                "height_placeholder" => "Height (nominal)",
            ],
            "PLATE" => [
                "length" => false,
                "width" => false,
                "height" => true,
                "length_placeholder" => "",
                "width_placeholder" => "",
                "height_placeholder" => "Thickness (mm)",
            ],
            "LVL" => [
                "length" => false,
                "width" => true,
                "height" => true,
                "length_placeholder" => "",
                "width_placeholder" => "Width (mm)",
                "height_placeholder" => "Height (mm)",
            ],
            "SHS" => [
                "length" => false,
                "width" => false,
                "height" => true,
                "length_placeholder" => "",
                "width_placeholder" => "",
                "height_placeholder" => "Height/Width (mm)",
            ],
            "RHS" => [
                "length" => false,
                "width" => true,
                "height" => true,
                "length_placeholder" => "x",
                "width_placeholder" => "Width (mm)",
                "height_placeholder" => "Height (mm)",
            ],
        ];
    }

//    public function senseChecks(): void
//    {
//        //todo
////        $unitRate = $this->senseCheckUnitRate($unitRate);
////        $lengthRequired = $this->senseCheckLengthRequired($lengthRequired,$measurementUnit);
//    }

    public function getLengthRequired($lengthRequiredColumnIndex,$row,$description): int
    {
        $result = null;

        /**
         * If has a "length required" column
         */
        if($lengthRequiredColumnIndex){
            //Has length in description
            $lengthInDescription = $this->searchLengthRequiredInDescription($description);
            if($lengthInDescription){
                $result = $lengthInDescription;
            }
            //NO length in description
            else{
                $result = $this->normaliseLengthWidthRequired($row[$lengthRequiredColumnIndex]);
            }
        }
        /**
         * NO "length required" column
         */
        else{
            //Search for length in description
            $result = $this->searchLengthRequiredInDescription($description);
        }

        //default to 1
        if(!$result){
            $result = 1;
        }

        return $result;
    }



    public function senseCheckUnitRate($unitRate)
    {
        /**
         * Sense check unit rate and update it if necessary
         */

        /**
         * Case #1
         * the description specified a length like PFC 9000mm, but this typically has METER units. Check that the rate matches METERS or MILLIMETERS.
         */
        //todo

        return $unitRate; //todo actual
    }

    public function senseCheckLengthRequired($lengthRequired,$measurementUnit)
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
        if($measurementUnit === MeasurementUnitEnums::SINGLE->value){
            $normalisedToMeters = $lengths;
        }
        //Millimeters
        if($measurementUnit === MeasurementUnitEnums::MILLIMETERS->value){
            foreach($lengths as $length){
                $normalisedToMeters[] = (float) $length/1000;
            }
        }
        //Meters
        if($measurementUnit === MeasurementUnitEnums::METERS->value){
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

    public function tokenizeSentence($sentence): array|false
    {
        // Use preg_split to split the sentence into words
        return preg_split('/\W+/', $sentence, -1, PREG_SPLIT_NO_EMPTY);
    }






//    public function getGeneralProductMatches(array $cleanRow): array
//    {
//        $getGeneralProductMatches = [];
//        if(count($cleanRow["products_unconfirmed"]) > 0){
//            $getGeneralProductMatches = $cleanRow["products_unconfirmed"]["generalProductMatches"];
//        }
//
//        return $getGeneralProductMatches;
//    }

//    public function getProductPossibilities(array $cleanRow): array
//    {
//        /**
//         * Possibilities that are non-length attributed
//         */
//        $getProductPossibilities = [];
//
//        if(count($cleanRow["products_unconfirmed"]) > 0){
//            foreach($cleanRow["products_unconfirmed"] as $product){
//                dd($product,$cleanRow["products_unconfirmed"]);
//                //$getProductPossibilities[] = $product->id;
//            }
//        }
//
//        return $getProductPossibilities;
//    }





    public function validationUserCustom($rows): array
    {
        $validator = Validator::make([], []);
        $validationErrors = 0;

        foreach($rows as $index => $row){
            $product = $row["selected"]["product"];
            $material = $row["selected"]["material"];
            $grade = $row["selected"]["grade"];
            $nominalLength = $row["selected"]["nominal_length"];
            $nominalWidth = $row["selected"]["nominal_width"];
            $nominalHeight = $row["selected"]["nominal_height"];
            $measurementUnit = $row["selected"]["quantify"];
            $nestingType = $row["selected"]["nesting_algo"];
            $purchasable_length_1 = $row["selected"]["purchasable_length_1"];
            $purchasable_length_2 = $row["selected"]["purchasable_length_2"];
            $purchasable_length_3 = $row["selected"]["purchasable_length_3"];
            $purchasable_width_1 = $row["selected"]["purchasable_width_1"];
            $purchasable_width_2 = $row["selected"]["purchasable_width_2"];
            $purchasable_width_3 = $row["selected"]["purchasable_width_3"];

            //product
            if($product){
                if($product === "Other" && !$row['selected_other']['product']){
                    $validationErrors++;
                    $validator->errors()->add($index."-product", 'product');
                }
            }
            else{
                $validationErrors++;
                $validator->errors()->add($index."-product", 'product');
            }

            //material
            if($material){
                if($material === "Other" && !$row['selected_other']['material']){
                    $validationErrors++;
                    $validator->errors()->add($index."-material", 'material');
                }
            }
            else{
                $validationErrors++;
                $validator->errors()->add($index."-material", 'material');
            }

            //Grade
            if($grade){
                if($grade === "Other" && !$row['selected_other']['grade']){
                    $validationErrors++;
                    $validator->errors()->add($index."-grade", 'grade');
                }
            }
            else{
                $validationErrors++;
                $validator->errors()->add($index."-grade", 'grade');
            }

            /*
             * Size
             */
            if(isset($row["nominalSizeData"][$product])){
                $shouldHaveLength = $row["nominalSizeData"][$product]["length"];
                if($shouldHaveLength){
                    if(!$nominalLength){
                        $validationErrors++;
                        $validator->errors()->add($index."-nominal_length", 'nominal_length');
                    }
                }
                $shouldHaveWidth = $row["nominalSizeData"][$product]["width"];
                if($shouldHaveWidth){
                    if(!$nominalWidth){
                        $validationErrors++;
                        $validator->errors()->add($index."-nominal_width", 'nominal_width');
                    }
                }
                $shouldHaveHeight = $row["nominalSizeData"][$product]["height"];
                if($shouldHaveHeight){
                    if(!$nominalHeight){
                        $validationErrors++;
                        $validator->errors()->add($index."-nominal_height", 'nominal_height');
                    }
                }
            }

            //Nesting & measurement units
            if($nestingType){
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
                if($nestingType === "NONE"){
                    //No actions
                }
                /*
                 * BUNDLE
                 * - Measurement units required: FALSE
                 * - Size required: TRUE
                 * - purchasable_length_1: TRUE
                 * - purchasable_width_1: FALSE
                 */
                if($nestingType === "BUNDLE"){
                    //Size required: TRUE
                    //todo
//                    if(!$size){
//                        $validationErrors++;
//                        $validator->errors()->add($index."-size", 'size');
//                    }
                    //purchasable_length_1: TRUE
                    if(!$purchasable_length_1){
                        $validationErrors++;
                        $validator->errors()->add($index."-purchasable_length_1", 'purchasable_length_1');
                    }
                }
                /*
                 * METERAGE
                 * - Measurement units required: TRUE
                 * - Size required: TRUE
                 * - purchasable_length_1: TRUE
                 * - purchasable_width_1: FALSE
                 */
                if($nestingType === "METERAGE"){
                    //Measurement units required: TRUE
                    if(!$measurementUnit){
                        $validationErrors++;
                        $validator->errors()->add($index."-quantify", 'quantify');
                    }
                    //Size required: TRUE
                    //todo
//                    if(!$size){
//                        $validationErrors++;
//                        $validator->errors()->add($index."-size", 'size');
//                    }
                    //purchasable_length_1: TRUE
                    if(!$purchasable_length_1){
                        $validationErrors++;
                        $validator->errors()->add($index."-purchasable_length_1", 'purchasable_length_1');
                    }
                }
                /*
                 * AREA
                 * - Measurement units required: TRUE
                 * - Size required: FALSE
                 * - purchasable_length_1: TRUE
                 * - purchasable_width_1: TRUE
                 */
                if($nestingType === "AREA"){
                    //Measurement units required: TRUE
                    if(!$measurementUnit){
                        $validationErrors++;
                        $validator->errors()->add($index."-quantify", 'quantify');
                    }
                    //purchasable_length_1: TRUE
                    if(!$purchasable_length_1){
                        $validationErrors++;
                        $validator->errors()->add($index."-purchasable_length_1", 'purchasable_length_1');
                    }
                    //purchasable_width_1: TRUE
                    if(!$purchasable_width_1){
                        $validationErrors++;
                        $validator->errors()->add($index."-purchasable_width_1", 'purchasable_width_1');
                    }
                    //Must have L and W (purchasable_length_2 && purchasable_width_2)
                    if($purchasable_length_2 || $purchasable_width_2){
                        if(!$purchasable_length_2){
                            $validationErrors++;
                            $validator->errors()->add($index."-purchasable_length_2", 'purchasable_length_2');
                        }
                        if(!$purchasable_width_2){
                            $validationErrors++;
                            $validator->errors()->add($index."-purchasable_width_2", 'purchasable_width_2');
                        }
                    }
                    //Must have L and W (purchasable_length_3 && purchasable_width_3)
                    if($purchasable_length_3 || $purchasable_width_3){
                        if(!$purchasable_length_3){
                            $validationErrors++;
                            $validator->errors()->add($index."-purchasable_length_3", 'purchasable_length_3');
                        }
                        if(!$purchasable_width_3){
                            $validationErrors++;
                            $validator->errors()->add($index."-purchasable_width_3", 'purchasable_width_3');
                        }
                    }
                    //L greater than W
                }
            }
            else{
                $validationErrors++;
                $validator->errors()->add($index."-nesting_algo", 'nesting_algo');
            }
        }

        return [
            "validationErrors" => $validationErrors,
            "validator" => $validator,
        ];
    }

    public function generateProductLabel(
        string $product,
        ?float $nominal_length,
        ?float $nominal_width,
        ?float $nominal_height,
        ?string $grade,
        ?string $surface
    ): string
    {
        //Grade
        $actualGrade = $grade;
        if ($grade === "NONE") {
            $actualGrade = "";
        }
        if ($grade === "GR_4_6" || $grade === "4.6S") {
            $actualGrade = "GR4.6";
        }
        if ($grade === "GR_8_8" || $grade === "8.8S") {
            $actualGrade = "GR8.8";
        }

        //Surface
        $actualSurface = ' '.$surface;
        if ($surface === "NONE") {
            $actualSurface = " ";
        }
        if ($surface === "GALVANISED") {
            $actualSurface = " GALV";
        }
        if ($surface === "TREATED_H2") {
            $actualSurface = " H2";
        }

        $result = "";

        if($product === "BOLT"){
            $result = $this->formatBOLT($product, $nominal_length, $nominal_width, $nominal_height, $actualGrade, $actualSurface);
        }
        else if($product === "UB"){
            $result = $this->formatUB($product, $nominal_length, $nominal_width, $nominal_height, $actualGrade, $actualSurface);
        }
        else if($product === "UC"){
            $result = $this->formatUC($product, $nominal_length, $nominal_width, $nominal_height, $actualGrade, $actualSurface);
        }
        else if($product === "PFC"){
            $result = $this->formatPFC($product, $nominal_length, $nominal_width, $nominal_height, $actualGrade, $actualSurface);
        }
        else if($product === "PLATE"){
            $result = $this->formatPLATE($product, $nominal_length, $nominal_width, $nominal_height, $actualGrade, $actualSurface);
        }
        else if($product === "LVL"){
            $result = $this->formatLVL($product, $nominal_length, $nominal_width, $nominal_height, $actualGrade, $actualSurface);
        }
        else if($product === "SHS"){
            $result = $this->formatSHS($product, $nominal_length, $nominal_width, $nominal_height, $actualGrade, $actualSurface);
        }
        else if($product === "RHS"){
            $result = $this->formatRHS($product, $nominal_length, $nominal_width, $nominal_height, $actualGrade, $actualSurface);
        }
        else{
            $result = $this->formatDefault($product, $nominal_length, $nominal_width, $nominal_height, $actualGrade, $actualSurface);
        }

        return $result;
    }

    private function formatDefault($product, $nominal_length, $nominal_width, $nominal_height, $actualGrade, $actualSurface): string
    {
        return "Nominal ".$product." ".$actualGrade.$actualSurface;
    }

    private function formatBOLT($product, $nominal_length, $nominal_width, $nominal_height, $actualGrade, $actualSurface): string
    {
        //Size
        $actualSize = "";
        if ($nominal_length) {
            $actualSize = "M".$nominal_width."x".$nominal_length;
        } else {
            $actualSize = "M".$nominal_width;
        }

        return $actualSize." ".$actualGrade.$actualSurface;
    }

    private function formatUB($product, $nominal_length, $nominal_width, $nominal_height, $actualGrade, $actualSurface): string
    {
        $actualSize = $nominal_height;
        return $actualSize." ".$actualGrade.$actualSurface;
    }

    private function formatUC($product, $nominal_length, $nominal_width, $nominal_height, $actualGrade, $actualSurface): string
    {
        $actualSize = $nominal_height;
        return $actualSize." ".$actualGrade.$actualSurface;
    }

    private function formatPFC($product, $nominal_length, $nominal_width, $nominal_height, $actualGrade, $actualSurface): string
    {
        $actualSize = $nominal_height;
        return $actualSize.$product." ".$actualGrade.$actualSurface;
    }

    private function formatPLATE($product, $nominal_length, $nominal_width, $nominal_height, $actualGrade, $actualSurface): string
    {
        $actualSize = $nominal_height."PL";
        return $actualSize." ".$actualGrade.$actualSurface;
    }

    private function formatLVL($product, $nominal_length, $nominal_width, $nominal_height, $actualGrade, $actualSurface): string
    {
        $actualSize = $nominal_height."x".$nominal_width;
        return $actualSize." ".$actualGrade.$actualSurface;
    }

    private function formatSHS($product, $nominal_length, $nominal_width, $nominal_height, $actualGrade, $actualSurface): string
    {
        $actualSize = $nominal_height."x".$nominal_width;
        return $actualSize." ".$actualGrade.$actualSurface;
    }

    private function formatRHS($product, $nominal_length, $nominal_width, $nominal_height, $actualGrade, $actualSurface): string
    {
        $actualSize = $nominal_height."x".$nominal_width;
        return $actualSize." ".$actualGrade.$actualSurface;
    }
}

