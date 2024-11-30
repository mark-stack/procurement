<?php

namespace App\Services;

use App\Enums\MeasurementUnitEnums;
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
        $firstPurchasableQtyIndexes = $template->first_purchasable_qty_cell
            ? $this->spreadsheetCoordinateToIndexes($template->first_purchasable_qty_cell)
            : null; //"purchasable qty" is optional
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
        $purchasableQtyColumnIndex = $firstPurchasableQtyIndexes
            ? $firstPurchasableQtyIndexes["column_index"]
            : null; //"measurement unit" is optional
        $subQtyColumnIndex = $firstSubQtyIndexes["column_index"];
        $unitRateColumnIndex = $firstUnitRateIndexes["column_index"];

        foreach($data as $index => $row){
            if($index >= $firstRowIndex){
                if($row[$descriptionColumnIndex] !== ""){
                    $cleanData[] = [
                        "index" => $index,
                        "description" => $row[$descriptionColumnIndex],
                        "material" => $materialColumnIndex
                            ? $row[$materialColumnIndex]
                            : $this->searchMaterialInDescription($row[$descriptionColumnIndex]),
                        "measurement_unit" => $measurementUnitColumnIndex
                            ? $row[$measurementUnitColumnIndex]
                            : $this->searchMeasurementUnitInDescription($row[$descriptionColumnIndex]),
                        "purchasable_qty" => $purchasableQtyColumnIndex
                            ? $this->normalisePurchasableQty($row[$purchasableQtyColumnIndex])
                            : $this->searchPurchasableQtyInDescription($row[$descriptionColumnIndex]),
                        "sub_qty" => $this->getSubQty($row[$subQtyColumnIndex]),
                        "unit_rate" => $this->getUnitRateDollars($row[$unitRateColumnIndex]),
                    ];
                }
            }
        }

        return $cleanData;
    }

    public function getUnitRateDollars(string $rawUnitRate): int
    {
        /**
         * This is to distinguish if the rate column is meterage or unit.
         * e.g "9m of 200PFC at $300". Is it $300/m, or $300 for the whole 9m span?
         *
         * consider:
         *  - purchasable qty
         *  - measurement unit
         */

        return 999; //todo: needs actual processing
    }

    public function getSubQty(string $rawSubQty): int
    {
        /**
         * This is to distinguish if unit rate being used as "meters" (meterage) or as singlar units.
         * e.g "9m of 200PFC with subQty=9". Is it 9 meters, or 9 spans of 9m?
         */

        return 999; //todo: needs actual processing
    }

    public function normalisePurchasableQty(string $rawPurchasableQty): float
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

    public function searchPurchasableQtyInDescription(string $description): int
    {
        /**
         * Find the purchasable qty
         *
        - e.g PFC - measurement_unit = "meters", and purchasable_qty = [9,12,13.5,15,18]
        - e.g bolts - measurement_unit = "single", and purchasable_qty = [50,100]
        - e.g flange - measurement_unit = "single", and purchasable_qty = [1]
        - e.g 16PL x 1220mm mild steel plate - measurement_unit = "millimeters", and purchasable_qty = [2440,3000]
         */

        //todo: allowed for comma like "9,000mm"

        $purchasableQty = 1; //todo needs actual processing

        return $purchasableQty;
    }

    public function searchMeasurementUnitInDescription(string $description): string
    {
        /**
         * Find the measurement unit (e.g "meters", "millimeters", "single")
         */

        $unitResult = null;

        // Regex to match a number followed by a measurement unit (including space variations)
        $pattern = '/\b\d+(\.\d+)?\s?(m{1,2}|meters|millimeters)\b/i';

        if (preg_match($pattern, $description, $matches)) {
            // Return the unit type in uppercase if matched
            $unit = strtolower($matches[2]);
            if ($unit === 'm' || $unit === 'mm') {
                // Handle special cases for m and mm
                $unitResult = $unit === 'm'
                    ? MeasurementUnitEnums::METERS->value
                    : MeasurementUnitEnums::MILLIMETERS->value;
            } elseif ($unit === 'meters') {
                $unitResult = MeasurementUnitEnums::METERS->value;
            } elseif ($unit === 'millimeters') {
                $unitResult = MeasurementUnitEnums::MILLIMETERS->value;
            }
        }

        //Default if no result found yet. e.g steel sections will be METERS
        $defaultGroup = [
            "METERS" => [
                "PFC", "Parallel Flange Channel", "Parallel Flanged Channel",
                "UB", "universal beam","universal beams",
                "UC", "universal column","universal columns",
                "SHS", "square hollow section","square hollow sections",
                "RHS", "rectangular hollow section","rectangular hollow sections",
                "CHS", "circular hollow section","circular hollow sections",
                "UBS", "Universal Beam Section","Universal Beam Sections",
                "UCS", "Universal Column Section","Universal Column Sections",
                "HSS","Hollow Structural Section","Hollow Structural Sections",
                "EA", "equal angle","equal angles",
                "UA", "unequal angle","unequal angles",
                "RSJ", "rolled steel joist","rolled steel joists",
                "Flat Bar","Flat Bars",
                "round bar","round bars",
                "Square Bar","Square Bars",
                "Rebar","Reinforcement Bar","Reinforcement Bars",
                "I-Beam","I-Beams",
                "Steel Joist","Steel Joists",
                "Steel Column","Steel Columns",
                "Steel Beam","Steel Beams",
                "Steel Channel","Steel Channels",
                "Steel Angles","Steel Angles",
                "pipe","pipes","piping",
                "Tube","Tubes","tubing",
                "Z-Section","Z-Sections",
                "T-Section","T-Sections",
                "Corrugated",
            ],
            "MILLIMETERS" => [
                "Plate","Plates",
                "Threaded Rod","Threaded Rods","allthread",
                "bolt","bolts",
                "U-Bolt","U-Bolts",
                "anchor","anchors",
                "Screw","Screws",
                "Shear Stud","Shear Studs",
                "Rivet","Rivets",
            ],
        ];
        if(!$unitResult){
            foreach ($defaultGroup as $unitTitle => $materialGroup) {
                foreach ($materialGroup as $keyword) {
                    // Use preg_match with word boundaries to avoid partial matches
                    $pattern = '/\b' . preg_quote($keyword, '/') . '\b/i';
                    if (preg_match($pattern, $description)) {
                        $unitResult = $unitTitle; // Return the first matching keyword
                    }
                }
            }
        }

        //Final default = single
        if(!$unitResult){
            $unitResult = MeasurementUnitEnums::SINGLE->value;
        }

        return $unitResult;
    }

    public function searchMaterialInDescription(string $description): ?string
    {
        /**
         * Find the material (e.g "mild steel", "stainless steel", "MDF")
         */

        $allMaterials = [
            //Metals
            "MILD STEEL" => [
                "mild steel",
                "mild",
                "ms",
                "plain carbon",
            ],
            "STAINLESS STEEL" => [
                "stainless steel",
                "ss",
                "ss316",
                "ss 316",
                "316ss",
                "ss304",
                "ss 304",
                "304ss",
            ],
            "TIMBER" => [
                "timber",
                "MDF",
                "SPF",
            ],
        ];

        $match = null;
        $words = $this->tokenizeSentence($description);
        foreach ($allMaterials as $materialGroupTitle => $materialGroup) {
            foreach($materialGroup as $material){
                foreach($words as $word){
                    if(strtoupper($word) === strtoupper($material)){
                        $match = $materialGroupTitle; // Return the first match
                        break;
                    }
                }
            }
        }

        /**
         * If no initial material match, do a last minute "default" material. e.g PFC = MILD STEEL
         */
        $defaultGroups = [
            "MILD STEEL" => [
                "PFC", "Parallel Flange Channel", "Parallel Flanged Channel",
                "UB", "universal beam","universal beams",
                "UC", "universal column","universal columns",
                "SHS", "square hollow section","square hollow sections",
                "RHS", "rectangular hollow section","rectangular hollow sections",
                "CHS", "circular hollow section","circular hollow sections",
                "UBS", "Universal Beam Section","Universal Beam Sections",
                "UCS", "Universal Column Section","Universal Column Sections",
                "HSS","Hollow Structural Section","Hollow Structural Sections",
                "EA", "equal angle","equal angles",
                "UA", "unequal angle","unequal angles",
                "RSJ", "rolled steel joist","rolled steel joists",
                "Flat Bar","Flat Bars",
                "round bar","round bars",
                "Square Bar","Square Bars",
                "Plate","Plates",
                "Rebar","Reinforcement Bar","Reinforcement Bars",
                "Threaded Rod","Threaded Rods","allthread",
                "I-Beam","I-Beams",
                "Steel Joist","Steel Joists",
                "Steel Column","Steel Columns",
                "Steel Beam","Steel Beams",
                "Steel Channel","Steel Channels",
                "Steel Angles","Steel Angles",
                "Steel Tube","Steel Tubes",
            ],
//            "STAINLESS STEEL" => [
//                //
//            ],
            "TIMBER" => [
                "LVL",
                "F17",
            ],
        ];
        if(!$match){
            foreach ($defaultGroups as $materialGroupTitle => $materialGroup) {
                foreach ($materialGroup as $keyword) {
                    // Use preg_match with word boundaries to avoid partial matches
                    $pattern = '/\b' . preg_quote($keyword, '/') . '\b/i';
                    if (preg_match($pattern, $description)) {
                        $match = $materialGroupTitle; // Return the first matching keyword
                    }
                }
            }
        }

        return $match;
    }

    public function tokenizeSentence($sentence) {
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
                "purchasable_qty" => $cleanRow["purchasable_qty"],
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

                    //"purchasable_qty" => $userProductData->purchasable_qty,  //todo: make separate
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
}

