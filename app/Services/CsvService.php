<?php

namespace App\Services;

use App\Models\Piece;
use App\Models\Project;
use App\Models\RawMaterialQuote;
use App\Models\User;
use Exception;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\File;

class CsvService
{
    /**
     * @deprecated
     */
    public function csvToArray(string $path): array
    {
        /**
         * Single purpose: convert the CSV into array
         */

        $data = [];
        if (($handle = fopen(storage_path("app/private/{$path}"), 'r')) !== false) {
            while (($row = fgetcsv($handle, 1000, ',')) !== false) {
                $data[] = $row;
            }
            fclose($handle);
        }

        return $data;
    }

    public function processCsv(array $csvArray, Project $project): RedirectResponse
    {
        /**
         * Single purpose: detect template matches
         */

        //Eligible Tables
        $eligibleTables = $this->eligibleTables();

        //Detected Tables
        $detectedTables = $this->detectedTables($csvArray,$eligibleTables);

        //Should have at least 1 result
        if(count($detectedTables) > 0){
            //Process data
            $this->processTemplate($detectedTables, $project);

            //Return back without warnings
            $return = back();
        }
        else{
            $return = back()->with("warning","The file didn't auto-detect properly. Did the template change? Please email the file to mark.laravel.coder@gmail to have it re-calibrated quickly.");
        }

        return $return;
    }

    public function eligibleTables(): array
    {
        //Users
        $authUser = auth()->user();

        $eligibleTables = [];
        foreach(config("TableTemplates") as $table){
            $ownerDomain = $table["ownerDomain"];

            if($this->isEligibleForThisTable($authUser,$ownerDomain)){
                $eligibleTables[] = $table;
            }
        }

        return $eligibleTables;
    }

    public function isEligibleForThisTable(object $authUser, string|null $domain): bool
    {
        /**
         * Single purpose: check if this template specifically is eligible for this user
         */

        //For everybody
        $condition_1 = $domain === null;

        //Is admin (sees everything)
        $condition_2 = $authUser->isAdmin();

        //For this business
        $condition_3 = strtoupper($authUser->getDomainFromEmail()) === strtoupper($domain);

        return $condition_1 || $condition_2 || $condition_3;
    }

    /**
     * @deprecated
     */
    public function templatesDetected(array $csvArray, User $projectUser): array
    {
        $templatesDetected = [];

        $eligibleTemplateClasses = $this->getEligibleTemplateClasses($projectUser);

        foreach($eligibleTemplateClasses as $eligibleTemplateClass){
            if($this->templateDetected($csvArray,$eligibleTemplateClass)){
                //Add to list of templates detected
                $templatesDetected[] = $eligibleTemplateClass;
            }
        }

        return $templatesDetected;
    }

    /**
     * @deprecated
     */
    public function getEligibleTemplateClasses(User $user): array
    {
        /**
         * Single purpose: get eligible templates for this user
         */
        $eligibleTemplates = [];

        $implementations = $this->getTemplateImplementations();
        foreach($implementations as $implementation){
            $className = 'App\\Services\\TemplateImplementations\\'.$implementation;

            // Check if the class exists
            if (class_exists($className)) {
                $service = new $className();
                $domain = $service->ownerDomain();

                if($this->isEligibleForThisTemplate($user, $domain)){
                    $eligibleTemplates[] = $service;
                }
            }
        }

        return $eligibleTemplates;
    }

    /**
     * @deprecated
     */
    public function isEligibleForThisTemplate(User $user, string|null $domain): bool
    {
        /**
         * Single purpose: check if this template specifically is eligible for this user
         */

        //Prerequisite variables
        $authUser = auth()->user();
        $isAdmin = $authUser->isAdmin();

        //For everybody
        $condition_1 = $domain === null;

        //Is admin (sees everything)
        $condition_2 = $isAdmin;

        //For this business
        $condition_3 = strtoupper($user->getDomainFromEmail()) === strtoupper($domain);

        return $condition_1 || $condition_2 || $condition_3;
    }

    /**
     * @deprecated
     */
    public function getTemplateImplementations(): array
    {
        $directory = app_path('Services/TemplateImplementations');
        return collect(File::files($directory))
            ->map(function ($file) {
                return $file->getFilename();
            })
            ->map(function ($filename) {
                return pathinfo($filename, PATHINFO_FILENAME);
            })
            ->values()
            ->toArray();
    }

    /**
     * @deprecated
     */
    public function templateDetected(array $csvArray, object $templateClass): bool
    {
        /**
         * Single purpose: detect template by the 3x fixed cell references
         */
        $templateDetected = false;

        $tripleCellData = $templateClass->confirmDocumentTripleCell();
        $spreadsheet_coordinate_1 = $tripleCellData[0]["spreadsheet_coordinate"];
        $spreadsheet_coordinate_2 = $tripleCellData[1]["spreadsheet_coordinate"];
        $spreadsheet_coordinate_3 = $tripleCellData[2]["spreadsheet_coordinate"];
        $text_1 = $tripleCellData[0]["text"];
        $text_2 = $tripleCellData[1]["text"];
        $text_3 = $tripleCellData[2]["text"];

        try {
            /**
             * Random cell match #1
             */
            $randomCellSpreadSheetCoordinate_1 = $this->spreadsheetCoordinateToIndexes($spreadsheet_coordinate_1);
            $colIndex_1 = $randomCellSpreadSheetCoordinate_1["column_index"];
            $rowIndex_1 = $randomCellSpreadSheetCoordinate_1["row_index"];
            $randomCellText_1 = $text_1;
            $matchRandomCell_1 = strcasecmp($csvArray[$rowIndex_1][$colIndex_1], $randomCellText_1) === 0;

            /**
             * Random cell match #2
             */
            $randomCellSpreadSheetCoordinate_2 = $this->spreadsheetCoordinateToIndexes($spreadsheet_coordinate_2);
            $colIndex_2 = $randomCellSpreadSheetCoordinate_2["column_index"];
            $rowIndex_2 = $randomCellSpreadSheetCoordinate_2["row_index"];
            $randomCellText_2 = $text_2;
            $matchRandomCell_2 = strcasecmp($csvArray[$rowIndex_2][$colIndex_2], $randomCellText_2) === 0;

            /**
             * Random cell match #3
             */
            $randomCellSpreadSheetCoordinate_3 = $this->spreadsheetCoordinateToIndexes($spreadsheet_coordinate_3);
            $colIndex_3 = $randomCellSpreadSheetCoordinate_3["column_index"];
            $rowIndex_3 = $randomCellSpreadSheetCoordinate_3["row_index"];
            $randomCellText_3 = $text_3;
            $matchRandomCell_3 = strcasecmp($csvArray[$rowIndex_3][$colIndex_3], $randomCellText_3) === 0;

            $templateDetected = $matchRandomCell_1 && $matchRandomCell_2 && $matchRandomCell_3;
        }
        catch (Exception $e) {
            $templateDetected = false;
        }

        return $templateDetected;
    }

    public function processTemplate(array $detectedTables, Project $project): void
    {
        /**
         * Single purpose: extract the materials from all the tables detected
         */

        //Services
        $dataClassificationService = new DataClassificationService();

        foreach($detectedTables as $tableInstance){
            $type = $tableInstance["type"];
            $rows = $tableInstance["data"];
            $predeterminedProductCategory = $tableInstance["predeterminedProductCategory"];

            //Sense checks
            //$productService->senseChecks(); //todo incomplete

            //Find price book products
            $dataWithProducts = $dataClassificationService->findProductsFromCleanData(
                $rows,
                $project->user,
                $predeterminedProductCategory
            );

            //Save user material list
            $this->saveRawMaterialQuoteData(
                $dataWithProducts,
                $project,
                $predeterminedProductCategory
            );

            //Create new user-custom products
            //todo is incomplete
            $this->createUserCustomProducts($dataWithProducts,$project);
        }
    }

    public function detectedTables(array $csvArray, array $eligibleTables): array
    {
        /**
         * Single purpose: detect multiple tables within this document
         */
        $tables = [];

        //Look through all rows
        foreach($csvArray as $index => $csvRow){
            $blankRow = count(array_filter($csvRow, function ($value) { return $value !== null; })) === 0;
            if(!$blankRow){
                $detectTableInstances = $this->detectTable($csvArray, $csvRow, $index, $eligibleTables);
                if(count($detectTableInstances) > 0){
                    foreach($detectTableInstances as $table){
                        $tables[] = $table;
                    }
                }
            }
        }

        return $tables;
    }

    /**
     * @deprecated
     */
//    public function detectedTables(array $csvArray, object $templateClass): array
//    {
//        /**
//         * Single purpose: detect multiple tables within this document
//         */
//        $tables = [];
//        $tableOptions = $templateClass->tableOptions();
//
//        //Check all table options
//        foreach($tableOptions as $tableOption){
//            //Look through all rows
//            foreach($csvArray as $index => $csvRow){
//                $detectTable = $this->detectTable($csvArray,$csvRow,$index,$templateClass,$tableOption);
//                if($detectTable){
//                    $tables[] = $detectTable;
//                }
//            }
//        }
//
//        return $tables;
//    }

    public function detectTable(array $csvArray, array $csvRow, int $index, array $tableOptions): ?array
    {
        /**
         * Single purpose: detect table within this document based on heading row match
         */
        $detectTableInstances = [];

        foreach($tableOptions as $tableOption){
            $firstDataRowIndex = $this->firstDataRowIndex($csvRow,$index,$tableOption);
            if($firstDataRowIndex){
                $detectTableInstances[] = [
                    "type" => $tableOption["type"],
                    "data" => $this->getTableData($csvArray,$firstDataRowIndex,$tableOption),
                    "predeterminedProductCategory" => $tableOption["predeterminedProductCategory"],
                ];
            }
        }

        return $detectTableInstances;
    }

//    /**
//     * @deprecated
//     */
//    public function detectTable(array $csvArray, array $csvRow, int $index, object $templateClass, array $tableOption): ?array
//    {
//        /**
//         * Single purpose: detect table within this document based on heading row match
//         */
//        $tableData = null;
//
//        $firstDataRowIndex = $this->firstDataRowIndex($csvRow,$index,$tableOption);
//        if($firstDataRowIndex){
//            $tableData = $this->getTableData($csvArray,$firstDataRowIndex,$templateClass,$tableOption);
//        }
//
//        return $tableData;
//    }

    public function firstDataRowIndex(array $csvRow, int $index, array $tableOption): ?int
    {
        /**
         * Single purpose:
         */
        $firstDataRowIndex = null;

        if($this->isTableHeader($csvRow,$tableOption)){
            $firstDataRowIndex = $index + $tableOption["OffsetFromHeaderToFirstDataRow"];
        }

        return $firstDataRowIndex;
    }

    public function getTableData(array $csvArray, int $firstDataRowIndex, array $tableOption): array
    {
        /**
         * Single purpose: return the derived table data like:
         */
        $tableData = [];

        //get specific indexes
        $descriptionColumnIndex = isset($tableOption["DescriptionColumnNumber"])
            ? ($tableOption["DescriptionColumnNumber"] - 1)
            : null;
        $materialColumnIndex = isset($tableOption["MaterialColumnNumber"])
            ? ($tableOption["MaterialColumnNumber"] - 1)
            : null;
        $gradeColumnIndex = isset($tableOption["GradeColumnNumber"])
            ? ($tableOption["GradeColumnNumber"] - 1)
            : null;
        $surfaceColumnIndex = isset($tableOption["SurfaceColumnNumber"])
            ? ($tableOption["SurfaceColumnNumber"] - 1)
            : null;
        $lengthRequiredColumnIndex = isset($tableOption["LengthColumnNumber"])
            ? ($tableOption["LengthColumnNumber"] - 1)
            : null;
        $widthRequiredColumnIndex = isset($tableOption["WidthColumnNumber"])
            ? ($tableOption["WidthColumnNumber"] - 1)
            : null;
        $subQtyColumnIndex = isset($tableOption["SubQtyColumnNumber"])
            ? ($tableOption["SubQtyColumnNumber"] - 1)
            : null;
        $unitRateColumnIndex = isset($tableOption["UnitRateColumnNumber"])
            ? ($tableOption["UnitRateColumnNumber"] - 1)
            : null;

        $nominalUnits = $tableOption["nominalUnits"];

        $skipOrFinishCheckColumnIndex = $tableOption['skipOrFinishCheckColumnNumber'] - 1;

        //Loop through CSV
        foreach($csvArray as $index => $csvRow) {
            //If at or below the 1st data row
            if ($index >= $firstDataRowIndex) {
                //End of table rule
                $shouldFinish = $this->shouldFinish($tableOption["isLastDataRow"],$csvArray, $csvRow, $index, $skipOrFinishCheckColumnIndex);
                if($shouldFinish){
                    break;
                }

                //Skip rule
                $shouldSkip = $this->shouldSkip($tableOption["ShouldSkipRow"], $csvRow, $skipOrFinishCheckColumnIndex);

                //Description
                $description = ($descriptionColumnIndex !== null && isset($csvRow[$descriptionColumnIndex]))
                    ? $csvRow[$descriptionColumnIndex]
                    : null;
                $canHaveNoDescription = $tableOption["predeterminedProductCategory"] !== null;

                if (!$shouldSkip && ($description || (!$description && $canHaveNoDescription))){
                    //Material
                    $material = ($materialColumnIndex !== null && isset($csvRow[$materialColumnIndex]))
                        ? $csvRow[$materialColumnIndex]
                        : null;

                    //Grade
                    $grade = ($gradeColumnIndex !== null && isset($csvRow[$gradeColumnIndex]))
                        ? $csvRow[$gradeColumnIndex]
                        : null;

                    //Surface
                    $surface = ($surfaceColumnIndex !== null && isset($csvRow[$surfaceColumnIndex]))
                        ? $csvRow[$surfaceColumnIndex]
                        : null;

                    //Length required
                    $lengthRequired = ($lengthRequiredColumnIndex !== null && isset($csvRow[$lengthRequiredColumnIndex]))
                        ? $this->normaliseLengthWidthRequired($csvRow[$lengthRequiredColumnIndex],$nominalUnits)
                        : null;

                    //Width required
                    $widthRequired = ($widthRequiredColumnIndex !== null && isset($csvRow[$widthRequiredColumnIndex]))
                        ? $this->normaliseLengthWidthRequired($csvRow[$widthRequiredColumnIndex],$nominalUnits)
                        : null;

                    //Sub qty
                    $subQty = isset($csvRow[$subQtyColumnIndex])
                        ? $this->getSubQty($csvRow[$subQtyColumnIndex])
                        : null;

                    //Unit rate
                    $unitRate = ($unitRateColumnIndex !== null && isset($csvRow[$unitRateColumnIndex]))
                        ? $this->getUnitRateDollars($csvRow[$unitRateColumnIndex])
                        : null;

                    $tableData[] = [
                        "index" => $index,
                        "description" => $description,
                        "material" => $material,
                        "grade" => $grade,
                        "surface" => $surface,
                        "length_required" => $lengthRequired,
                        "width_required" => $widthRequired,
                        "sub_qty" => $subQty,
                        "unit_rate" => $unitRate,
                    ];
                }
            }
        }

        return $tableData;
    }

    public function isTableHeader(array $csvRow, array $tableOption): bool
    {
        /**
         * Single purpose: confirm if this CSV row matches a known table header
         */

        $expectedHeadingLabels = $tableOption["ExpectedHeadingLabels"];

        $isTableHeader = false;

        /*
         * First check if the row contains at least the first heading label before determining order which is computationally expensive
         */
        $csvRow = array_map('strtoupper', $csvRow);
        $hasAtLeastOne = in_array(strtoupper($expectedHeadingLabels[0]),$csvRow);

        if($hasAtLeastOne){
            //Check exact order of heading titles
            $index = 0; // Index for expectedOrder
            foreach ($csvRow as $columnValue) {
                if(isset($expectedHeadingLabels[$index])){
                    if (strtoupper($columnValue) === strtoupper($expectedHeadingLabels[$index])) {
                        $index++;
                        if ($index === count($expectedHeadingLabels)) {
                            $isTableHeader = true; // All values matched in order
                        }
                    }
                }
            }

//            //todo debug
//            if($expectedHeadingLabels[0] === "Profile" && $csvRow[0] !== "MARK"){
//                dd("one",$csvRow,$tableOption,$expectedHeadingLabels[0],$isTableHeader,$index);
//            }
        }

        return $isTableHeader;
    }

    /**
     * @deprecated
     */
    public function projectQuoteTemplateProcessing(array $csvArray, object $projectQuoteTemplate, Project $project): void
    {
        /**
         * Single purpose: extract the materials via an admin-configured template
         */

        //Services
        $dataClassificationService = new DataClassificationService();

        //Clean the data (but no default assumptions yet)
        $cleanCsvData = $this->cleanCsvData($csvArray,$projectQuoteTemplate);

        //Sense checks
        //$productService->senseChecks(); //todo incomplete

        //Find price book products
        $dataWithProducts = $dataClassificationService->findProductsFromCleanData($cleanCsvData,$project->user);

        //Save user material list
        $cleanMaterialList = $this->saveRawMaterialQuoteData($dataWithProducts,$project);

        //Create new user-custom products
        //todo is incomplete
        $this->createUserCustomProducts($dataWithProducts,$project);
    }

    public function spreadsheetCoordinateToIndexes(string $coordinate): array
    {
        /**
         * Single purpose: convert spreadsheet coordinate like "B5" to an X,Y index pair "1,4"
         */

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

    /**
     * @deprecated
     */
    public function cleanCsvData(array $data, object $template): array
    {
        /**
         * Single purpose: clean & normalised the CSV data
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

    public function getSubQty(string $rawSubQty): float
    {
        /**
         * Single purpose: convert sub qty to a float. Also set "1" as default to avoid zero multiplication
         */

        $float = (float) $rawSubQty;
        return $float === 0 ? 1.0 : $float;
    }

    public function normaliseLengthWidthRequired(string $quantity, string $lengthWidthUnits): float
    {
        /**
         * Single purpose: extract just the number from string number representation.
         */

        //$float = 1.0; //default
        $removeLetters = preg_replace('/[a-zA-Z]/', '', $quantity);
        $removeCurrencySymbols =preg_replace('/[€£¥₹$¢₱₽₩₦฿]/u', '', $removeLetters);

        return (float) $removeCurrencySymbols;

//        if (is_numeric($removeCurrencySymbols)) {
//            //If template length/width is METERS
//            if($lengthWidthUnits === "m"){
//                $float = (float) $removeCurrencySymbols;
//            }
//            //If template length/width is MILLIMETERS
//            if($lengthWidthUnits === "mm"){
//                $float = (float) ($removeCurrencySymbols/1000);
//            }
//        }
//
//        return $float;
    }

    public function getUnitRateDollars(string $rawUnitRate): float
    {
        /**
         * Single purpose: extract float numbers from string
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

    public function createUserCustomProducts(array $dataWithProducts, object $project): void
    {
        /**
         * Single purpose: create custom products not found in general price book
         * todo: incomplete
         */

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
//                    "nominal_units" => MeasurementUnitEnums::SINGLE->value, //todo: let the user customise
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

    public function saveRawMaterialQuoteData($dataWithProducts,$project, ?string $predeterminedProductCategory): array
    {
        /**
         * Single purpose:
         */

        //Services
        $dataClassificationService = new DataClassificationService();
        $productService = new ProductService();

        $materialList = [];
        foreach($dataWithProducts as $cleanRow){
            $productCategory = $dataClassificationService->findProduct($cleanRow["description"],$predeterminedProductCategory);
            $productCategoryDisplay = $productCategory ? $productCategory["productEnum"]->value : null;

            /**
             * Create 'RawMaterialQuote' item
             */
            $rawMaterialQuote = RawMaterialQuote::create([
                "csv_index" => $cleanRow["index"],
                "description" => $cleanRow["description"] ?? $productService->generateProductLabel(
                        $productCategoryDisplay,
                        $cleanRow["length_required"],
                        $cleanRow["width_required"],
                        null,
                        $cleanRow["grade"],
                        $cleanRow["surface"]
                    ),
                "product_category" => $productCategoryDisplay,
                "material" => $cleanRow["material"] ?? null,
                "grade" => $cleanRow["grade"] ?? null,
                "surface" => $cleanRow["surface"] ?? null,
                "nominal_units" => $dataClassificationService->findMeasurementUnit($productCategory),
                "length_required" => $cleanRow["length_required"],
                "width_required" => $cleanRow["width_required"] ?? null,
                "sub_qty" => $cleanRow["sub_qty"],
                "unit_rate" => $cleanRow["unit_rate"] ?? null,
                'project_id' => $project->id,
                "general_product_matches" => serialize($cleanRow["generalProductMatches"]),
            ]);

            $materialList[] = $rawMaterialQuote;

            /**
             * Create 'Pieces'
             */
            if(count($cleanRow["generalProductMatches"]) === 1){
                $item = $cleanRow["generalProductMatches"][0];

                $piece = Piece::create([
                    'project_id' => $project->id,
                    "raw_material_quote_id" => $rawMaterialQuote->id,
                    "product" => $item["product"],
                    "material" => $item["material"],
                    "grade" => $item["grade"],
                    "surface" => $item["surface"],
                    "nominal_units" => $item["nominal_units"],
                    "nesting_algo" => (new NestingService())->getNestingLabelsFromProduct($item["product"])[0],
                    "nominal_length" => $item["nominal_length"] ?? null,
                    "nominal_width" => $item["nominal_width"] ?? null,
                    "nominal_height" => $item["nominal_height"] ?? null,
                    "actual_length" => $cleanRow["length_required"], //For singular items like bolts, this is "QTY" that's divisible.
                    "actual_width" => $cleanRow["width_required"] ?? null,
                    "actual_qty" => $cleanRow["sub_qty"],
                ]);
            }
        }

        return $materialList;
    }

    public function shouldFinish(string|null $text, array $csvArray, array $csvRow, int $index, int $skipOrFinishCheckColumnIndex): bool
    {
        $shouldFinish = false;

        //Has text
        if($text){
            $shouldFinish = $this->isLastDataRowTextContains($text, $csvRow, $skipOrFinishCheckColumnIndex);
        }
        else{
            $shouldFinish = $this->isLastDataRow2BlankCells($csvArray, $index, $skipOrFinishCheckColumnIndex);
        }

        return $shouldFinish;
    }
    public function isLastDataRow2BlankCells(array $csvArray, int $index, int $skipOrFinishCheckColumnIndex): bool
    {
        /**
         * 2 consecutive blank 'description' cells
         */

        $thisCellBlank = true;
        if(isset($csvArray[$index][$skipOrFinishCheckColumnIndex])){
            $thisCell = $csvArray[$index][$skipOrFinishCheckColumnIndex];
            $thisCellBlank = $thisCell=== "" || $thisCell === null;
        }

        //Next row exists
        $nextCellBlank = true;
        if(isset($csvArray[$index + 1][$skipOrFinishCheckColumnIndex])){
            $nextCell = $csvArray[$index + 1][$skipOrFinishCheckColumnIndex];
            $nextCellBlank = $nextCell === "" || $nextCell === null;
        }

//        //todo debug
//        if($index === 107){
//            dd([
//                "index" => $index,
//                "skipOrFinishCheckColumnIndex" => $skipOrFinishCheckColumnIndex,
//                "this row" => $csvArray[$index][$skipOrFinishCheckColumnIndex],
//                "next row" => $csvArray[$index + 1][$skipOrFinishCheckColumnIndex],
//                "cond 1" => isset($csvArray[$index][$skipOrFinishCheckColumnIndex]),
//                "cond 2" => isset($csvArray[$index + 1][$skipOrFinishCheckColumnIndex]),
//            ]);
//        }

        return $thisCellBlank && $nextCellBlank;
    }

    public function isLastDataRowTextContains(string $text, array $csvRow, int $skipOrFinishCheckColumnIndex): bool
    {
        /**
         * Description cell contains specific
         */
        $result = false;

        if(isset($csvRow[$skipOrFinishCheckColumnIndex])){
            $result = strtoupper($csvRow[$skipOrFinishCheckColumnIndex]) === strtoupper($text);
        }

        return $result;
    }

    public function shouldSkip(string|null $text, array $csvRow, int $skipOrFinishCheckColumnIndex): bool
    {
        $shouldSkip = false;

        //Blank row
        $blankRow = count(array_filter($csvRow, function ($value) { return $value !== null; })) === 0;
        if($blankRow){
            $shouldSkip = true;
        }
        else{
            //Has text
            if($text){
                $shouldSkip = $this->shouldSkipRowDescriptionTextContains($text,$csvRow,$skipOrFinishCheckColumnIndex);
            }
            else{
                $shouldSkip = $this->shouldSkipRowBlankDescription($csvRow,$skipOrFinishCheckColumnIndex);
            }
        }

        return $shouldSkip;
    }

    public function shouldSkipRowBlankDescription(array $csvRow, int $descriptionColumnIndex): bool
    {
        /**
         * If description column is blank
         */
        $skip = false;

        if(isset($csvRow[$descriptionColumnIndex])){
            $skip =  $csvRow[$descriptionColumnIndex] === "" || $csvRow[$descriptionColumnIndex] === null;
        }

        return $skip;
    }

    public function shouldSkipRowDescriptionTextContains(string $text, array $csvRow, int $descriptionColumnIndex): bool
    {
        /**
         * Description cell contains specific
         */
        return strtoupper($csvRow[$descriptionColumnIndex]) === strtoupper($text);
    }

}
