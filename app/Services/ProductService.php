<?php

namespace App\Services;

use App\Enums\MaterialEnums;
use App\Enums\MeasurementUnitEnums;
use App\Enums\GradeEnums;
use App\Enums\ProductEnums;
use App\Enums\SurfaceEnums;
use App\Models\Product;
use App\Models\RawMaterialQuote;
use App\Models\Template;
use Exception;

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
            $domain = $this->getDomainFromEmail($projectUser->email);
            $userTemplates = Template::query()
                ->where("domain",$domain)
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

    public function cleanCsvData(array $data, object $template): array
    {
        /**
         * clean the CSV data
         */
        $cleanData = [];

        $firstDescriptionIndexes = $this->spreadsheetCoordinateToIndexes($template->first_description_cell);
        $firstMaterialIndexes = $template->first_material_cell
            ? $this->spreadsheetCoordinateToIndexes($template->first_material_cell)
            : null; //"material" is optional
        $firstMeasurementUnitIndexes = $template->first_measurement_unit_cell
            ? $this->spreadsheetCoordinateToIndexes($template->first_measurement_unit_cell)
            : null; //"measurement unit" is optional
        $firstLengthRequiredIndexes = $template->first_length_required_cell
            ? $this->spreadsheetCoordinateToIndexes($template->first_length_required_cell)
            : null; //"length_required" is optional
        $firstSubQtyIndexes = $this->spreadsheetCoordinateToIndexes($template->first_sub_qty_cell);
        $firstUnitRateIndexes = $this->spreadsheetCoordinateToIndexes($template->first_unit_rate_cell);

        $firstRowIndex = $firstDescriptionIndexes["row_index"];

        $descriptionColumnIndex = $firstDescriptionIndexes["column_index"];
        $materialColumnIndex = $firstMaterialIndexes
            ? $firstMaterialIndexes["column_index"]
            : null; //"material" is optional
        $measurementUnitColumnIndex = $firstMeasurementUnitIndexes
            ? $firstMeasurementUnitIndexes["column_index"]
            : null; //"measurement unit" is optional
        $lengthRequiredColumnIndex = $firstLengthRequiredIndexes
            ? $firstLengthRequiredIndexes["column_index"]
            : null; //"measurement unit" is optional
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
                        : $this->searchMaterialInDescription($description);

                    //Measurement unit
                    $measurementUnit = $measurementUnitColumnIndex
                        ? $row[$measurementUnitColumnIndex]
                        : $this->searchMeasurementUnitInDescription($description);

                    //Length required
                    $lengthRequired = $this->getLengthRequired($lengthRequiredColumnIndex,$row,$description);

                    //Sub qty
                    $subQty = $this->getSubQty($row[$subQtyColumnIndex]);

                    //Unit rate
                    $unitRate = $this->getUnitRateDollars($row[$unitRateColumnIndex]);

                    /**
                     * Sense checks
                     */
                    $unitRate = $this->senseCheckUnitRate($unitRate);
                    $lengthRequired = $this->senseCheckLengthRequired($lengthRequired,$measurementUnit);

                    $cleanData[] = [
                        "index" => $index,
                        "description" => $description,
                        "material" => $material,
                        "measurement_unit" => $measurementUnit,
                        "length_required" => $lengthRequired,
                        "sub_qty" => $subQty,
                        "unit_rate" => $unitRate,
                    ];
                }
            }
        }

        return $cleanData;
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
                $result = $this->normaliseLengthRequired($row[$lengthRequiredColumnIndex]);
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

//        "id" => 1
//        "created_at" => "2024-12-01 05:04:25"
//        "updated_at" => "2024-12-01 05:04:25"
//        "csv_index" => 27
//        "description" => "Steel Beams (I-Beams)"
//        "material" => "MILD STEEL"
//        "measurement_unit" => "METERS"
//        "length_required" => "1"
//        "sub_qty" => "1"
//        "unit_rate" => "0"
//        "project_id" => 2

        return true; //todo actual processing
    }

    public function normaliseLengthRequired(string $rawPurchasableQty): float
    {
        /**
         * Convert string numbers into integer
         */

        $removeLetters = preg_replace('/[a-zA-Z]/', '', $rawPurchasableQty);
        $removeCurrencySymbols =preg_replace('/[€£¥₹$¢₱₽₩₦฿]/u', '', $removeLetters);

        if (is_numeric($removeCurrencySymbols)) {
            return (float) $removeCurrencySymbols;
        } else {
            // Return null or handle non-numeric inputs as needed
            return 1.0;
        }
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

    public function findProductsFromCleanData(array $cleanMaterialList): array
    {
        $result = [];

        foreach($cleanMaterialList as $row){
            $productsInRow = $this->findProductsInRow($row);

            $append = $row;
            $append["products_confirmed"] = $productsInRow["products_confirmed"];
            $append["products_unconfirmed"] = $productsInRow["products_unconfirmed"];
            $append["product_custom_for_user"] = $productsInRow["product_custom_for_user"];
            $result[] = $append;
        }

        return $result;
    }

    public function saveRawMaterialQuoteData($cleanCsvData,$project): array
    {
        $materialList = [];
        foreach($cleanCsvData as $cleanRow){
            $materialList[] = RawMaterialQuote::create([
                "csv_index" => $cleanRow["index"],
                "description" => $cleanRow["description"],
                "material" => $cleanRow["material"],
                "measurement_unit" => $cleanRow["measurement_unit"],
                "length_required" => $cleanRow["length_required"],
                "sub_qty" => $cleanRow["sub_qty"],
                "unit_rate" => $cleanRow["unit_rate"],
                'project_id' => $project->id,
            ]);
        }

        return $materialList;
    }

    public function saveConfirmedProducts(array $dataWithProducts, object $project): void
    {
        foreach($dataWithProducts as $row){
            if(count($row["products_confirmed"]) > 0){
                foreach($row["products_confirmed"] as $product){
                    //todo save product
                }
            }
        }
    }

    public function createUserCustomProducts(array $dataWithProducts, object $project): void
    {
        $user = $project->user;
        $domain = $this->getDomainFromEmail($user->email);

        foreach($dataWithProducts as $row){
            $userProductData = $row["product_custom_for_user"];
            if($userProductData !== null){
                Product::create([
                    "description" => $userProductData->description,
                    "material" => $userProductData->material,
                    "measurement_unit" => $userProductData->measurement_unit,
                    'domain' => $domain,
                ]);
            }
        }
    }

    public function getUnconfirmedRows(array $dataWithProducts): array
    {
        $getUnconfirmedRows = [];

        foreach($dataWithProducts as $row){
            if(count($row["products_unconfirmed"]) > 0){
                foreach($row["products_unconfirmed"] as $product){
                    $getUnconfirmedRows[] = [
                        "row_data" => $row,
                        "index" => $row["index"],
                        "product" => $product,
                    ];
                }
            }
        }

        return $getUnconfirmedRows;
    }

    public function findProductsInRow(object $rowObject): array
    {
        return [
            "products_confirmed" => [],//todo placeholder
            "products_unconfirmed" => [],//todo placeholder
            "product_custom_for_user" => $rowObject, //todo placeholder
        ];
    }

    public function getDomainFromEmail(string $email): ?string
    {
        $pattern = '/@([a-zA-Z0-9.-]+\.[a-zA-Z]{2,6})/';
        if (preg_match($pattern, $email, $matches)) {
            return $matches[1]; // Domain is captured in the first group
        }
        return null; // Return null if no domain found
    }

    public function findProduct(string $text): ?array
    {
        $resultProduct = null;

        $products = [
            //PFC
            [
                "productEnum" => ProductEnums::PFC,
                "productRegex" => [
                    "PFC",
                    "Parallel+\s+Flange+\s+Channel",
                    "Parallel+\s+Flanged+\s+Channel",
                ],
                "sizeRegex" => [
                    "(\d+)+PFC",       //200PFC
                    "(\d+)+\s+PFC",    //200 PFC
                ],
                "lengthRegex" => [
                    "\b(\d+(\.\d+)?)\s?(m|meter|meters|mm|millimeters)\b", //meterage //todo: only METERS?
                ],
                "measurementUnit" => MeasurementUnitEnums::METERS,
                "defaultMaterial" => MaterialEnums::STEEL,
                "defaultGrade" => GradeEnums::GR250,
                "defaultSurface" => SurfaceEnums::NONE,
                "availableSizes" => [
                    [
                        "size" => 150,
                        "length" => 9,
                    ],
                    [
                        "size" => 150,
                        "length" => 12,
                    ],
                    [
                        "size" => 200,
                        "length" => 9,
                    ],
                    [
                        "size" => 200,
                        "length" => 12,
                    ],
                    [
                        "size" => 200,
                        "length" => 13.5,
                    ],
                    [
                        "size" => 200,
                        "length" => 15,
                    ],
                ],
            ],
            //UB
            [
                "productEnum" => ProductEnums::UB,
                "productRegex" => [
                    "\d+UB",
                    "\d+\s+UB",
                    "universal beam",
                ],
                "sizeRegex" => [
                    "(\d+)+UB",    //300UB
                    "(\d+)+\s+UB", //300 UB
                ],
                "lengthRegex" => [
                    "\b(\d+(\.\d+)?)\s?(m|meter|meters|mm|millimeters)\b", //meterage //todo: only METERS?
                ],
                "measurementUnit" => MeasurementUnitEnums::METERS,
                "defaultMaterial" => MaterialEnums::STEEL,
                "defaultGrade" => GradeEnums::GR250,
                "defaultSurface" => SurfaceEnums::NONE,
                "availableSizes" => [
                    [
                        "size" => 150,
                        "length" => 9,
                    ],
                    //todo more
                ],
            ],
            //UC
            [
                "productEnum" => ProductEnums::UC,
                "productRegex" => [
                    "\d+UC",
                    "\d+\s+UC",
                    "column",
                ],
                "sizeRegex" => [
                    "(\d+)+UC",    //300UC
                    "(\d+)+\s+UC", //300 UC
                ],
                "lengthRegex" => [
                    "\b(\d+(\.\d+)?)\s?(m|meter|meters|mm|millimeters)\b", //meterage //todo: only METERS?
                ],
                "measurementUnit" => MeasurementUnitEnums::METERS,
                "defaultMaterial" => MaterialEnums::STEEL,
                "defaultGrade" => GradeEnums::GR250,
                "defaultSurface" => SurfaceEnums::NONE,
                "availableSizes" => [
                    [
                        "size" => 150,
                        "length" => 9,
                    ],
                    //todo more
                ],
            ],
            //Steel plate
            [
                "productEnum" => ProductEnums::PLATE,
                "productRegex" => [
                    "Plate",
                    "\d+PL",
                    "\d+\s+PL",
                ],
                "sizeRegex" => [
                    "(\d+)+PL",    //16PL
                    "(\d+)+mm",    //16mm
                    "(\d+)+\s+mm", //16 mm
                ],
                "lengthRegex" => [
                    "\b(\d+(\.\d+)?)\s?(m|meter|meters|mm|millimeters)\b", //meterage //todo: only METERS?
                ],
                "measurementUnit" => MeasurementUnitEnums::SINGLE,
                "defaultMaterial" => MaterialEnums::STEEL,
                "defaultGrade" => GradeEnums::GR250,
                "defaultSurface" => SurfaceEnums::NONE,
                "availableSizes" => [
                    [
                        "size" => 1220,
                        "length" => 2440,
                    ],
                    [
                        "size" => 1220,
                        "length" => 3000,
                    ],
                    //todo more
                ],
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
                "measurementUnit" => MeasurementUnitEnums::SINGLE,
                "defaultMaterial" => MaterialEnums::STEEL,
                "defaultGrade" => GradeEnums::GR_4_6,
                "defaultSurface" => SurfaceEnums::GALVANISED,
                "availableSizes" => [
                    [
                        "size" => "M16",
                        "length" => 25,
                    ],
                    [
                        "size" => "M16",
                        "length" => 40,
                    ],
                    [
                        "size" => "M16",
                        "length" => 50,
                    ],
                    [
                        "size" => "M16",
                        "length" => 60,
                    ],
                    //todo more
                ],
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
                "measurementUnit" => MeasurementUnitEnums::METERS,
                "defaultMaterial" => MaterialEnums::TIMBER,
                "defaultGrade" => GradeEnums::NONE,
                "defaultSurface" => SurfaceEnums::TREATED,
                "availableSizes" => [
                    [
                        "size" => "90X63",
                        "length" => 3.0,
                    ],
                    [
                        "size" => "90X63",
                        "length" => 3.6,
                    ],
                    [
                        "size" => "90X63",
                        "length" => 4.2,
                    ],
                    [
                        "size" => "90X63",
                        "length" => 4.8,
                    ],
                    [
                        "size" => "90X63",
                        "length" => 5.4,
                    ],
                    [
                        "size" => "90X63",
                        "length" => 6.0,
                    ],
                    [
                        "size" => "90X63",
                        "length" => 7.2,
                    ],
                    //todo more
                ],
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
    public function findMaterial($product): ?MaterialEnums
    {
        return $product["defaultMaterial"];
    }

    public function findGrade($product,$text): ?GradeEnums
    {
        $gradeResult = null;

        $grades = [
            //GR 250
            [
                "gradeEnum" => GradeEnums::GR250,
                "regex" => [
                    "Mild",
                    "MS",
                    "GR250",
                    "GRADE+\s+250",
                ],
            ],
            //GR 350
            [
                "gradeEnum" => GradeEnums::GR350,
                "regex" => [
                    "GR350",
                    "GRADE+\s+350",
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
                    $gradeResult = $grade["gradeEnum"];
                }
            }
        }

        /**
         * Default grade
         */
        if(!$gradeResult){
            $gradeResult = $product["defaultGrade"];
        }

        return $gradeResult;
    }
    public function findSurface($product,$text,$foundGrade): ?SurfaceEnums
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
        if(!$surfaceResult){
            $isException = false;

            //SS316 bolts
            if($product["productEnum"] === ProductEnums::BOLT && $foundGrade === GradeEnums::SS316){
                $isException = true;
            }

            //SS304 bolts
            if($product["productEnum"] === ProductEnums::BOLT && $foundGrade === GradeEnums::SS304){
                $isException = true;
            }

            if(!$isException){
                $surfaceResult = $product["defaultSurface"];
            }
        }

        return $surfaceResult;
    }

    public function findMeasurementUnit($product,$text): ?MeasurementUnitEnums
    {
        /**
         * Measurement unit determined by product.
         * e.g PFC is always METERS
         */

        return $product["measurementUnit"]; //$unitResult;
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

