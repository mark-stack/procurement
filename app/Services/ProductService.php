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
            $domain = $projectUser->getDomainFromEmail();
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
            $append["product_confirmed"] = $productsInRow["product_confirmed"];
            $append["products_unconfirmed"] = $productsInRow["products_unconfirmed"];
            $append["product_custom_for_user"] = $productsInRow["product_custom_for_user"];
            $result[] = $append;
        }

        return $result;
    }

    public function saveRawMaterialQuoteData($dataWithProducts,$project): array
    {
        $materialList = [];
        foreach($dataWithProducts as $cleanRow){
            $productCategory = $this->findProduct($cleanRow["description"]);

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
                "product_id" => $cleanRow["product_confirmed"] ? $cleanRow["product_confirmed"]->id : null,
                "count_unconfirmed_possibilities" => count($this->getUnconfirmedRows($cleanRow)),
            ]);
        }

        return $materialList;
    }

    public function saveConfirmedProducts(array $dataWithProducts, object $project): void
    {
        foreach($dataWithProducts as $row){
            $productConfirmed = $row["product_confirmed"];
            if($productConfirmed !== null){
                $project->products()->attach($productConfirmed->id, ["quantity" => $row["sub_qty"]]);
            }
        }
    }

    public function createUserCustomProducts(array $dataWithProducts, object $project): void
    {
        $user = $project->user;
        $domain = $user->getDomainFromEmail();

        foreach($dataWithProducts as $row){
            $userProductData = $row["product_custom_for_user"];
            if($userProductData !== null){

//                $userProductData
//                "index" => 27
//                "description" => "Steel Beams (I-Beams)"
//                "material" => null
//                "length_required" => 12.0
//                "sub_qty" => 2.0
//                "unit_rate" => 50.0

                Product::create([
                    "description" => $userProductData["description"],
                    "product" => ProductEnums::PFC, //todo: let the user customise
                    "material" => $userProductData["material"] ?? MaterialEnums::STEEL->value, //todo: let the user customise
                    "grade" => GradeEnums::NONE->value, //todo: let the user customise
                    "surface" => SurfaceEnums::NONE->value, //todo: let the user customise
                    "measurement_unit" => MeasurementUnitEnums::SINGLE->value, //todo: let the user customise
                    "size" => 1, //todo: let the user customise
                    "length" => $userProductData["length_required"], //todo: let the user customise
                    "width" => $userProductData["width_required"], //todo: let the user customise
                    "kg_per_m" => 1, //todo: let the user customise
                    "baseline_unit_rate" => 1, //todo: let the user customise
                    'domain' => $domain,
                ]);
            }
        }
    }

    public function getUnconfirmedRows(array $cleanRow): array
    {
        $getUnconfirmedRows = [];


        if(!isset($cleanRow["products_unconfirmed"])){
            dd("cleanRow",$cleanRow);
        }


        if(count($cleanRow["products_unconfirmed"]) > 0){
            foreach($cleanRow["products_unconfirmed"] as $product){
                $getUnconfirmedRows[] = $product;
            }
        }

        return $getUnconfirmedRows;
    }

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
        $priceBookProducts = [];
        if($productCategory){
            //MATERIAL
            $material = $this->findMaterial($productCategory);

            //GRADE
            $grades = $this->findGrades($productCategory,$cleanCsvRow["description"]);

            //SURFACE
            $surface = $this->findSurface($productCategory,$cleanCsvRow["description"],$grades); //todo: description or cell?

            //MEASUREMENT_UNIT
            $measurementUnit = $this->findMeasurementUnit($productCategory); //todo: description or cell?

            //SIZE
            $size = $this->findSize($productCategory,$cleanCsvRow["description"]); //todo: description or cell?

            //LENGTH
            $length = $this->findLength($productCategory,$cleanCsvRow["description"]); //todo: description or cell?

//            dd([
//                $cleanCsvRow["description"],
//                $productCategory,
//                $material,
//                $grade,
//                $surface,
//                $measurementUnit,
//                $size,
//                $length,
//            ]);

            //Price book search
            $priceBookProducts = $this->findByAttributes(
                $user,
                $productCategory["productEnum"],
                $material,
                $grades,
                $surface,
                $measurementUnit,
                $size,
                $length,
            );


//            dd([
//                "description" => $cleanCsvRow["description"],
//                "productCategory" => $productCategory,
//                "material" => $material,
//                "grade" => $grade,
//                "surface" => $surface,
//                "measurementUnit" => $measurementUnit,
//                "size" => $size,
//                "length" => $length,
//                "priceBookProduct" => $priceBookProduct,
//            ]);
        }

        $productConfirmed = null;
        $productsUnconfirmed = [];
        $productCustomForUser = null;
        if(count($priceBookProducts) === 0){
            $productCustomForUser = $cleanCsvRow;
        }
        elseif(count($priceBookProducts) === 1){ //1 result = confirmed
            $productConfirmed = $priceBookProducts[0];
        }
        elseif(count($priceBookProducts) > 1){ //1+ results = unconfirmed
            $productsUnconfirmed[] = $priceBookProducts;
        }
        else{
            $productCustomForUser = $cleanCsvRow;
        }

        return [
            "product_confirmed" => $productConfirmed,
            "products_unconfirmed" => $productsUnconfirmed,
            "product_custom_for_user" => $productCustomForUser,
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
                    "(\d+)+PFC",       //200PFC
                    "(\d+)+\s+PFC",    //200 PFC
                ],
                "lengthRegex" => [
                    "\b(\d+(\.\d+)?)\s?(m|meter|meters|mm|millimeters)\b", //meterage //todo: only METERS?
                ],
                "widthRegex" => null,
                "measurementUnit" => MeasurementUnitEnums::METERS,
                "defaultMaterial" => MaterialEnums::STEEL,
                "defaultGrade" => GradeEnums::GR300,
                "defaultSurface" => SurfaceEnums::NONE,
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
                "defaultGrade" => GradeEnums::GR300,
                "defaultSurface" => SurfaceEnums::NONE,
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
                "defaultGrade" => GradeEnums::GR300,
                "defaultSurface" => SurfaceEnums::NONE,
            ],
            //Steel plate
            [
                "productEnum" => ProductEnums::PLATE,
                "productRegex" => [
                    "Plate",                //plate
                    "(\d+)+PL",             //20PL
                    "(\d+)+\s+PL",          //20 PL
                    "(\d+)+mm+\s+PL",       //20mm PL
                    "(\d+)+mm+\s+plate",    //20mm plate
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
                "defaultGrade" => GradeEnums::GR250,
                "defaultSurface" => SurfaceEnums::NONE,
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
                "defaultGrade" => GradeEnums::GR_4_6,
                "defaultSurface" => SurfaceEnums::GALVANISED,
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
                "defaultGrade" => GradeEnums::NONE,
                "defaultSurface" => SurfaceEnums::TREATED_H2,
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

    public function findGrades($product,$text): array
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
        if(!$gradeResults){
            $gradeResults = [
                $product["defaultGrade"]
            ];
        }

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
        if(!$surfaceResult){
            $isException = false;

            //SS316 bolts
            if($product["productEnum"] === ProductEnums::BOLT && in_array(GradeEnums::SS316,$foundGrades)){
                $isException = true;
            }

            //SS304 bolts
            if($product["productEnum"] === ProductEnums::BOLT && in_array(GradeEnums::SS304,$foundGrades)){
                $isException = true;
            }

            if(!$isException){
                $surfaceResult = $product["defaultSurface"];
            }
        }

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

