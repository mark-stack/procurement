<?php

namespace App\Services;

use App\Enums\MaterialEnums;
use App\Enums\MeasurementUnitEnums;
use App\Enums\GradeEnums;
use App\Enums\ProductEnums;
use App\Enums\SurfaceEnums;
use App\Models\Piece;
use App\Models\Product;
use App\Models\RawMaterialQuote;
use App\Models\Template;
use App\Models\User;
use Exception;
use Illuminate\Support\Collection;

class ProductService
{
    public function templatesDetected(array $data, object $projectUser): array
    {
        $templatesDetected = [];

        //admin sees all templates
        $authUser = auth()->user();
        $isAdmin = $authUser->isAdmin();

        $userTemplates = collect([]);
        if($isAdmin){
            $userTemplates = Template::all();
        }
        else{
            $userTemplates = Template::query()
                ->where("business_id",$projectUser->business->id)
                ->get();
        }

        //Check against each template
        foreach($userTemplates as $template){
            //Detects this template
            if($this->checkSingleTemplate($data,$template)){
                $templatesDetected[] = $template;
            }
        }

        return $templatesDetected;
    }

    public function findGeneralProductMatches(
        $user,
        $productString,
        $materialEnum,
        $gradesEnums,
        $surfaceEnum,
        $measurementUnitEnum,
        $sizeInt,
        $lengthInt,
    ): Collection
    {
        /**
         * Compare the limited attributes provided (grade, size, etc) against the master price book.
         *
         * NOTE: for METERAGE items, disregard length. For SINGLE items, length is "size". e.g a M16x50 bolt
         * So find the general "150PFC STEEL GR300" disregarding 9m,12m,etc.
         */
        $return = collect([]); //default

        //"Product" is mandatory
        if($productString) {
            if($lengthInt && $measurementUnitEnum && $measurementUnitEnum->value === "SINGLE"){
                $query = Product::select('product', 'material', 'grade', 'surface', 'measurement_unit', 'size','length')
                    ->distinct()
                    ->availableFor($user)
                    ->where("product", $productString);
            }
            else{
                $query = Product::select('product', 'material', 'grade', 'surface', 'measurement_unit', 'size')
                    ->distinct()
                    ->availableFor($user)
                    ->where("product", $productString);
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
                $query->where("measurement_unit", $measurementUnitEnum->value);
            }

            //Size
            if (!is_null($sizeInt)) {
                $query->where("size", $sizeInt);
            }

            //Length
            if($lengthInt && $measurementUnitEnum && $measurementUnitEnum->value === "SINGLE"){
                $query->where("length", $lengthInt);
            }

            return $query->get();
        }

        return $return;
    }

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
                $query->where("measurement_unit", $measurementUnit->value);
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

    /**
     * @deprecated
     */
    public function attributeGaps($productEnum, $material, $grades, $surface, $measurementUnit, $size): array
    {
        /**
         * Based on limited information available, what are the possibilities (length disregarded)
         * e.g "M16 SS316" might have "M16 SS316 GR4.6","M16 SS316 GR8.8".
         */

        $gaps = [];
        if($productEnum === null) {
            $gaps["PRODUCT"] = []; //This option won't happen, because not product means creating a custom product instead
        }
        if($material === null) {
            $materialOptions = [];
            if($productEnum){
                $materialOptions = Product::query()
                    ->where("product",$productEnum->value)
                    ->pluck("material")
                    ->toArray();
            }

            $gaps["MATERIAL"] = array_unique($materialOptions);
        }
        if($grades === null || count($grades) > 1) {
            if($grades === null){
                $gradeOptions = [];
                if($productEnum){
                    $gradeOptions = Product::query()
                        ->where("product",$productEnum->value)
                        ->pluck("grade")
                        ->toArray();
                }

                $gaps["GRADE"] = array_unique($gradeOptions);
            }
            elseif(count($grades) > 1){
                $allGradeOptions = Product::query()
                    ->where("product",$productEnum->value)
                    ->pluck("grade")
                    ->toArray();

                $gradeOptions = [];
                foreach($grades as $grade){
                    if(in_array($grade->value,$allGradeOptions )){
                        $gradeOptions[] = $grade->value;
                    }
                }
                $gaps["GRADE"] = $gradeOptions;
            }
        }
        if($surface === null) {
            $surfaceOptions = [];
            if($productEnum){
                $surfaceOptions = Product::query()
                    ->where("product",$productEnum->value)
                    ->pluck("surface")
                    ->toArray();
            }

            $gaps["SURFACE"] = array_unique($surfaceOptions);
        }
        if($measurementUnit === null) {
            $measurementUnitOptions = [];
            if($productEnum){
                $measurementUnitOptions = Product::query()
                    ->where("product",$productEnum->value)
                    ->pluck("measurement_unit")
                    ->toArray();
            }

            $gaps["MEASUREMENT_UNIT"] = array_unique($measurementUnitOptions);
        }
        if($size === null) {
            $sizeOptions = [];
            if($productEnum){
                $sizeOptions = Product::query()
                    ->where("product",$productEnum->value)
                    ->pluck("size")
                    ->toArray();
            }

            $gaps["SIZE"] = array_unique($sizeOptions);
        }

        /**
         * Generate options based on the other available attributes.
         * e.g if an M12 bolt only comes in GR4.6, don't display GR8.8 as an option.
         */

        return $gaps;
    }

    public function checkSingleTemplate(array $data, object $template): bool
    {
        /**
         * Check if this CSV data matches this template
         */

        $result = false;
        try {
            /**
             * Random cell match #1
             */
            $randomCellSpreadSheetCoordinate_1 = $this->spreadsheetCoordinateToIndexes($template->random_cell_1);
            $colIndex_1 = $randomCellSpreadSheetCoordinate_1["column_index"];
            $rowIndex_1 = $randomCellSpreadSheetCoordinate_1["row_index"];
            $randomCellText_1 = $template->random_cell_text_1;
            $matchRandomCell_1 = strcasecmp($data[$rowIndex_1][$colIndex_1], $randomCellText_1) === 0;

            /**
             * Random cell match #2
             */
            $randomCellSpreadSheetCoordinate_2 = $this->spreadsheetCoordinateToIndexes($template->random_cell_2);
            $colIndex_2 = $randomCellSpreadSheetCoordinate_2["column_index"];
            $rowIndex_2 = $randomCellSpreadSheetCoordinate_2["row_index"];
            $randomCellText_2 = $template->random_cell_text_2;
            $matchRandomCell_2 = strcasecmp($data[$rowIndex_2][$colIndex_2], $randomCellText_2) === 0;

            $result = $matchRandomCell_1 && $matchRandomCell_2;
        }
        catch (Exception $e) {
            $result = false;
        }

        return $result;
    }

    public function spreadsheetCoordinateToIndexes(string $coordinate): array
    {
        // Extract column part (letters)
        preg_match('/[A-Za-z]+/', $coordinate, $columnMatches);
        $columnString = $columnMatches[0];

        // Extract row part (numbers)
        preg_match('/\d+/', $coordinate, $rowMatches);
        $row = (int)$rowMatches[0];

        // Convert the column letters to a number (base-26)
        $columnNumber = 0;
        $columnLength = strlen($columnString);
        for ($i = 0; $i < $columnLength; $i++) {
            $columnNumber = $columnNumber * 26 + (ord(strtoupper($columnString[$i])) - ord('A') + 1);
        }

        return ['column_index' => ($columnNumber-1), 'row_index' => ($row-1)];
    }

    public function cleanCsvData(array $data, object $template, object $project): array
    {
        /**
         * clean the CSV data
         */
        $cleanData = [];

        //Get array of indexes from spreadsheet coordinates. e.g B3 to [2,1]
        $firstDescriptionIndexes = $this->spreadsheetCoordinateToIndexes($template->first_description_cell);
        $firstMaterialIndexes = $template->first_material_cell
            ? $this->spreadsheetCoordinateToIndexes($template->first_material_cell)
            : null; //"material" is optional
        $firstLengthRequiredIndexes = $template->first_length_required_cell
            ? $this->spreadsheetCoordinateToIndexes($template->first_length_required_cell)
            : null; //"length_required" is optional
        $firstWidthRequiredIndexes = $template->first_width_required_cell
            ? $this->spreadsheetCoordinateToIndexes($template->first_width_required_cell)
            : null; //"width_required" is optional
        $firstSubQtyIndexes = $this->spreadsheetCoordinateToIndexes($template->first_sub_qty_cell);
        $firstUnitRateIndexes = $this->spreadsheetCoordinateToIndexes($template->first_unit_rate_cell);
        $firstRowIndex = $firstDescriptionIndexes["row_index"];

        //get specific indexes
        $descriptionColumnIndex = $firstDescriptionIndexes["column_index"];
        $materialColumnIndex = $firstMaterialIndexes
            ? $firstMaterialIndexes["column_index"]
            : null; //"material" is optional
        $lengthRequiredColumnIndex = $firstLengthRequiredIndexes
            ? $firstLengthRequiredIndexes["column_index"]
            : null; //"length" is optional
        $widthRequiredColumnIndex = $firstWidthRequiredIndexes
            ? $firstWidthRequiredIndexes["column_index"]
            : null; //"width" is optional
        $subQtyColumnIndex = $firstSubQtyIndexes["column_index"];
        $unitRateColumnIndex = $firstUnitRateIndexes["column_index"];

        foreach($data as $index => $row){
            if($index >= $firstRowIndex){
                if($row[$descriptionColumnIndex] !== ""){
                    //Description
                    $description = $row[$descriptionColumnIndex];

                    //Material
                    $material = $materialColumnIndex
                        ? $row[$materialColumnIndex]
                        : null;

                    //Length required
                    $lengthRequired = $lengthRequiredColumnIndex
                        ? $this->normaliseLengthWidthRequired($row[$lengthRequiredColumnIndex],$template->length_width_units)
                        : null;

                    //Width required
                    $widthRequired = $widthRequiredColumnIndex
                        ? $this->normaliseLengthWidthRequired($row[$widthRequiredColumnIndex],$template->length_width_units)
                        : null;

                    //Sub qty
                    $subQty = $this->getSubQty($row[$subQtyColumnIndex]);

                    //Unit rate
                    $unitRate = $this->getUnitRateDollars($row[$unitRateColumnIndex]);

                    $cleanData[] = [
                        "index" => $index,
                        "description" => $description,
                        "material" => $material,
                        "length_required" => $lengthRequired,
                        "width_required" => $widthRequired,
                        "sub_qty" => $subQty,
                        "unit_rate" => $unitRate,
                    ];
                }
            }
        }

        return $cleanData;
    }

    public function senseChecks(): void
    {
//        $unitRate = $this->senseCheckUnitRate($unitRate);
//        $lengthRequired = $this->senseCheckLengthRequired($lengthRequired,$measurementUnit);
    }

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

    public function getUnitRateDollars(string $rawUnitRate): float
    {
        /**
         * This is to distinguish if the rate column is meterage or unit.
         * e.g "9m of 200PFC at $300". Is it $300/m, or $300 for the whole 9m span?
         *
         * consider:
         *  - purchasable qty
         *  - measurement unit
         */

        $result = 0;

        // Regular expression to match integers and floats
        $pattern = '/\b\d{1,3}(?:,\d{3})*(?:\.\d+)?|\b\d+(?:\.\d+)?\b/';

        // Perform regex match
        if (preg_match($pattern, $rawUnitRate, $matches)) {
            $result = $matches[0]; // Return the matched number
        }

        return (float) $result;
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

    public function getSubQty(string $rawSubQty): float
    {
        $float = (float) $rawSubQty;
        return $float === 0 ? 1.0 : $float;
    }

    public function isPurchasableSize($row): bool
    {
        /**
         * Find the purchasable qty
         *
        - e.g PFC - measurement_unit = "meters", and purchasable_qty = [9,12,13.5,15,18]
        - e.g bolts - measurement_unit = "single", and purchasable_qty = [50,100]
        - e.g flange - measurement_unit = "single", and purchasable_qty = [1]
        - e.g 16PL x 1220mm mild steel plate - measurement_unit = "millimeters", and purchasable_qty = [2440,3000]
         */

        $result = false;

        $measurementEnum = null;
        foreach(MeasurementUnitEnums::cases() as $enum){
            if($enum->value === $row["measurement_unit"]){
                $measurementEnum = $enum;
            }
        }

        $productEnum = null;
        foreach(ProductEnums::cases() as $enum){
            if($enum->value === $row["product_category"]){
                $productEnum = $enum;
            }
        }

        $priceBookProducts = $this->findByAttributes(
            auth()->user(),
            $productEnum,
            $row["material"],
            null, //$grades,
            null, //$surface,
            $measurementEnum,
            null, //$size,
            null //$length,
        );

        if($priceBookProducts->count() > 0){
            $lengths = $priceBookProducts->pluck('length')->toArray();
            $normalisedToMeters = $this->normaliseArrayOfLengthsToMeters($lengths,$row["measurement_unit"]);
            $providedLengthInMeters = (float) $row["length_required"];
            if(in_array($providedLengthInMeters,$normalisedToMeters)){
                $result = true;
            }
        }

        return $result;
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

    public function normaliseLengthWidthRequired(string $quantity, string $lengthWidthUnits): float
    {
        /**
         * Convert string numbers into integer
         */
        $float = 1.0; //default
        $removeLetters = preg_replace('/[a-zA-Z]/', '', $quantity);
        $removeCurrencySymbols =preg_replace('/[€£¥₹$¢₱₽₩₦฿]/u', '', $removeLetters);

        if (is_numeric($removeCurrencySymbols)) {
            //If template length/width is METERS
            if($lengthWidthUnits === "m"){
                $float = (float) $removeCurrencySymbols;
            }
            //If template length/width is MILLIMETERS
            if($lengthWidthUnits === "mm"){
                $float = (float) ($removeCurrencySymbols/1000);
            }
        }

        return $float;
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

    public function findProductsFromCleanData(array $cleanCsvData, User $user): array
    {
        $result = [];

        foreach($cleanCsvData as $cleanCsvRow){
            $productsInRow = $this->findProductsInRow($cleanCsvRow,$user);

            $append = $cleanCsvRow;
            $append["generalProductMatches"] = $productsInRow["generalProductMatches"];
            $result[] = $append;
        }

        return $result;
    }

    public function saveRawMaterialQuoteData($dataWithProducts,$project): array
    {
        $materialList = [];
        foreach($dataWithProducts as $cleanRow){
            $productCategory = $this->findProduct($cleanRow["description"]);

            /**
             * Create 'RawMaterialQuote' item
             */
            $materialList[] = RawMaterialQuote::create([
                "csv_index" => $cleanRow["index"],
                "description" => $cleanRow["description"],
                "product_category" => $productCategory ? $productCategory["productEnum"]->value : null,
                "material" => $cleanRow["material"] ?? null,
                "measurement_unit" => $this->findMeasurementUnit($productCategory),
                "length_required" => $cleanRow["length_required"],
                "width_required" => $cleanRow["width_required"],
                "sub_qty" => $cleanRow["sub_qty"],
                "unit_rate" => $cleanRow["unit_rate"],
                'project_id' => $project->id,
                "general_product_matches" => serialize($cleanRow["generalProductMatches"]),
            ]);

            /**
             * Create 'Pieces'
             */
            if(count($cleanRow["generalProductMatches"]) === 1){
                $item = $cleanRow["generalProductMatches"][0];

                $piece = Piece::create([
                    'project_id' => $project->id,
                    "product" => $item["product"],
                    "material" => $item["material"],
                    "grade" => $item["grade"],
                    "surface" => $item["surface"],
                    "measurement_unit" => $item["measurement_unit"],
                    "nesting_algo" => (new NestingService())->getNestingLabelsFromProduct($item["product"])[0],
                    "size" => $item["size"],
                    "actual_length" => $cleanRow["length_required"], //For singular items like bolts, this is "QTY" that's divisible.
                    "actual_width" => $cleanRow["width_required"],
                    "actual_qty" => $cleanRow["sub_qty"],
                ]);
            }
        }

        return $materialList;
    }

    public function createUserCustomProducts(array $dataWithProducts, object $project): void
    {
        $user = $project->user;
        $business = $user->business;
        $domain = $user->getDomainFromEmail();

//        foreach($dataWithProducts as $row){
//            $userProductData = $row["product_custom_for_user"];//todo
//            if($userProductData !== null){
//
////                $userProductData
////                "index" => 27
////                "description" => "Steel Beams (I-Beams)"
////                "material" => null
////                "length_required" => 12.0
////                "sub_qty" => 2.0
////                "unit_rate" => 50.0
//
//                Product::create([
//                    "description" => $userProductData["description"],
//                    "product" => ProductEnums::PFC, //todo: let the user customise
//                    "material" => $userProductData["material"] ?? MaterialEnums::STEEL->value, //todo: let the user customise
//                    "grade" => GradeEnums::NONE->value, //todo: let the user customise
//                    "surface" => SurfaceEnums::NONE->value, //todo: let the user customise
//                    "measurement_unit" => MeasurementUnitEnums::SINGLE->value, //todo: let the user customise
//                    "size" => 1, //todo: let the user customise
//                    "length" => $userProductData["length_required"], //todo: let the user customise
//                    "width" => $userProductData["width_required"], //todo: let the user customise
//                    "kg_per_m" => 1, //todo: let the user customise
//                    "baseline_unit_rate" => 1, //todo: let the user customise
//                    'domain' => $domain,
//                ]);
//            }
//        }
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

    public function findProductsInRow(array $cleanCsvRow, User $user): array
    {
//        $cleanCsvRow
//        "index" => 27
//        "description" => "Steel Beams (I-Beams)"
//        "material" => null
//        "measurement_unit" => null
//        "length_required" => 12.0
//        "sub_qty" => 2.0
//        "unit_rate" => 50.0

        //Product (category like "PFC")
        $productCategory = $this->findProduct($cleanCsvRow["description"]);

        //Has product
        $generalProductMatches = collect([]);
        $materialEnum = null;
        $gradesEnums = null;
        $surfaceEnum = null;
        $measurementUnitEnum = null;
        $sizeInt = null;
        $lengthInt = null;

        if($productCategory){
            //MATERIAL
            $materialEnum = $this->findMaterial($productCategory);

            //GRADE
            $gradesEnums = $this->findGrades($productCategory,$cleanCsvRow["description"]);

            //SURFACE
            $surfaceEnum = $this->findSurface($productCategory,$cleanCsvRow["description"],$gradesEnums); //todo this might be a column

            //MEASUREMENT_UNIT
            $measurementUnitEnum = $this->findMeasurementUnit($productCategory);

            //SIZE
            $sizeInt = $this->findSize($productCategory,$cleanCsvRow["description"]);

            //LENGTH
            $lengthInt = $this->findLength($productCategory,$cleanCsvRow["description"]);

            /**
             * Price book search
             */
            $generalProductMatches = $this->findGeneralProductMatches(
                $user,
                $productCategory["productEnum"]->value,
                $materialEnum,
                $gradesEnums,
                $surfaceEnum,
                $measurementUnitEnum,
                $sizeInt,
                $lengthInt,
            );
        }

        return [
            "cleanCsvRow" => $cleanCsvRow,
            "generalProductMatches" => $generalProductMatches->toArray(),
        ];
    }

    public function findProduct(string $text): ?array
    {
        $resultProduct = null;

        $products = [


//        "SHS", "square hollow section","square hollow sections",
//        "RHS", "rectangular hollow section","rectangular hollow sections",
//        "CHS", "circular hollow section","circular hollow sections",
//        "UBS", "Universal Beam Section","Universal Beam Sections",
//        "UCS", "Universal Column Section","Universal Column Sections",
//        "HSS","Hollow Structural Section","Hollow Structural Sections",
//        "EA", "equal angle","equal angles",
//        "Steel Angles","Steel Angles",
//        "UA", "unequal angle","unequal angles",
//        "RSJ", "rolled steel joist","rolled steel joists",
//        "Flat Bar","Flat Bars",
//        "round bar","round bars",
//        "Square Bar","Square Bars",
//        "Rebar","Reinforcement Bar","Reinforcement Bars",
//        "Threaded Rod","Threaded Rods","allthread",
//        "I-Beam","I-Beams",
//        "Steel Joist","Steel Joists",
//        "Steel Tube","Steel Tubes",
        //todo more


            //PFC
            [
                "productEnum" => ProductEnums::PFC,
                "productRegex" => [
                    "PFC",
                    "Parallel+\s+Flange+\s+Channel",
                    "Parallel+\s+Flanged+\s+Channel",
                    "steel+\s+channel",
                ],
                "sizeRegex" => [
                    "(\d+)+PFC",          //200PFC
                    "(\d+)+\s+PFC",       //200 PFC
                    "(\d+)+mm+\s+PFC",    //200mm PFC
                    "(\d+)+\s+mm+\s+PFC", //200 mm PFC
                    "(\d+)+\s+mm+\s+Parallel Flange Channel",    //200 mm Parallel Flange Channel
                    "(\d+)+mm+\s+Parallel+\s+Flange+\s+Channel", //200 mm Parallel Flange Channel
                ],
                "lengthRegex" => [
                    "\b(\d+(\.\d+)?)\s?(m|meter|meters|mm|millimeters)\b", //meterage //todo: only METERS?
                ],
                "widthRegex" => null,
                "measurementUnit" => MeasurementUnitEnums::METERS,
                "defaultMaterial" => MaterialEnums::STEEL,
            ],
            //UB
            [
                "productEnum" => ProductEnums::UB,
                "productRegex" => [
                    "\d+UB",
                    "\d+\s+UB",
                    "universal+\s+beam",
                    "steel+\s+beam",
                ],
                "sizeRegex" => [
                    "(\d+)+UB",    //300UB
                    "(\d+)+\s+UB", //300 UB
                ],
                "lengthRegex" => [
                    "\b(\d+(\.\d+)?)\s?(m|meter|meters|mm|millimeters)\b", //meterage //todo: only METERS?
                ],
                "widthRegex" => null,
                "measurementUnit" => MeasurementUnitEnums::METERS,
                "defaultMaterial" => MaterialEnums::STEEL,
            ],
            //UC
            [
                "productEnum" => ProductEnums::UC,
                "productRegex" => [
                    "\d+UC",
                    "\d+\s+UC",
                    "universal+\s+column",
                    "steel+\s+column",
                ],
                "sizeRegex" => [
                    "(\d+)+UC",    //300UC
                    "(\d+)+\s+UC", //300 UC
                ],
                "lengthRegex" => [
                    "\b(\d+(\.\d+)?)\s?(m|meter|meters|mm|millimeters)\b", //meterage //todo: only METERS?
                ],
                "widthRegex" => null,
                "measurementUnit" => MeasurementUnitEnums::METERS,
                "defaultMaterial" => MaterialEnums::STEEL,
            ],
            //Steel plate
            [
                "productEnum" => ProductEnums::PLATE,
                "productRegex" => [
                    "Plate",                //plate
                    "Plates",               //plates
                    "(\d+)+PL",             //20PL
                    "(\d+)+\s+PL",          //20 PL
                    "(\d+)+mm+\s+PL",       //20mm PL
                    "(\d+)+mm+\s+plate",    //20mm plate
                    "Steel+\s+Plate",       //Steel plate
                    "Steel+\s+Plates",      //steel plates
                ],
                "sizeRegex" => [
                    "\b(0|[1-9][0-9]?|1[0-4][0-9]|150) ?PL", //16PL or 16 PL
                    "\b(0|[1-9][0-9]?|1[0-4][0-9]|150) ?mm", //16mm or 16 mm
                ],
                "lengthRegex" => [
                    //don't attempt to get length. it'll get mixed up with width
                ],
                "widthRegex" => [
                    "(1200|1220|1800|2400|2440|3000|3200|1\.2|1\.8|1\.22|2\.4|2\.44|3\.0|3\.2)", //Find common plate widths in M or MM
                ],
                "measurementUnit" => MeasurementUnitEnums::MILLIMETERS,
                "defaultMaterial" => MaterialEnums::STEEL,
            ],
            //Bolts
            [
                "productEnum" => ProductEnums::BOLT,
                "productRegex" => [
                    "M+\d",
                    "bolt",
                ],
                "sizeRegex" => [
                    "M+(\d+)", //M16
                ],
                "lengthRegex" => [
                    "x+(\d+)",      //x100
                    "x+\s+(\d+)",   //x 100
                ],
                "widthRegex" => null,
                "measurementUnit" => MeasurementUnitEnums::SINGLE,
                "defaultMaterial" => MaterialEnums::STEEL,
            ],
            //LVL
            [
                "productEnum" => ProductEnums::LVL,
                "productRegex" => [
                    "LVL",
                ],
                "sizeRegex" => [
                    "(\d+)+x+(\d+)",         //100x100
                    "(\d+)+\s+X+\s+(\d+)",   //100 x 100
                ],
                "lengthRegex" => [
                    "\b(\d+(\.\d+)?)\s?(m|meter|meters|mm|millimeters)\b", //meterage //todo: only METERS?
                ],
                "widthRegex" => null,
                "measurementUnit" => MeasurementUnitEnums::METERS,
                "defaultMaterial" => MaterialEnums::TIMBER,
            ],
            //todo more
        ];

        foreach($products as $product){
            foreach($product["productRegex"] as $pattern){
                $regex = "/".$pattern."/i";
                if(preg_match($regex, $text)){
                    $resultProduct = $product;
                }
            }
        }

        return $resultProduct;
    }
    public function findMaterial($product): MaterialEnums
    {
        return $product["defaultMaterial"];
    }

    public function findGrades($product,$text): null|array
    {
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
            //SS304
            [
                "gradeEnum" => GradeEnums::SS304,
                "regex" => [
                    "SS304",
                    "SS+\s+304",
                    "304SS",
                    "304+\s+SS",
                ],
            ],
            //SS316
            [
                "gradeEnum" => GradeEnums::SS316,
                "regex" => [
                    "SS316",
                    "SS+\s+316",
                    "316SS",
                    "316+\s+SS",
                ],
            ],
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

    public function findSurface($product,$text,$foundGrades): ?SurfaceEnums
    {
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

    public function findMeasurementUnit($product): MeasurementUnitEnums
    {
        /**
         * Measurement unit determined by product.
         * e.g PFC is always METERS
         */

        return $product["measurementUnit"] ?? MeasurementUnitEnums::SINGLE;
    }
    public function findSize($product,$text): ?int
    {
        $resultInt = null;

        $sizeRegexPatterns = $product["sizeRegex"];

        foreach($sizeRegexPatterns as $pattern){
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
    public function findLength($product,$text): ?int
    {
        $result = null;

        foreach($product['lengthRegex'] as $pattern){
            $regex = "/".$pattern."/i";

            // Perform regex match
            if (preg_match($regex, $text, $matches)) {
                $result = $matches[1]; // Return the number part
            }
        }

        return $result;
    }
}

