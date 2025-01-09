<?php

namespace App\Services;

use App\Enums\MeasurementUnitEnums;
use App\Enums\NestingEnums;
use App\Models\Piece;
use App\Models\Project;
use App\Models\RawMaterialQuote;
use Illuminate\Http\RedirectResponse;

class CsvService
{
    public function processCsv(array $csvArray, Project $project, string $errorMsg): RedirectResponse
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
            $return = back()->with("warning",$errorMsg);
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

    public function processTemplate(array $detectedTables, Project $project): void
    {
        /**
         * Single purpose: extract the materials from all the tables detected
         */

        //Services
        $dataClassificationService = new DataClassificationService();
        $productService = new ProductService();

        foreach($detectedTables as $tableInstance){
            $type = $tableInstance["type"];
            $rows = $tableInstance["data"];

            //Sense checks
            //$productService->senseChecks(); //todo incomplete

            //Add general product matches to row data
            $rowDataWithGeneralProductMatches = [];
            foreach($rows as $row){
                /*
                 * Description (Use derived if no description column provided)
                 */
                if(!$row["description"]){
                    $productConfig = $dataClassificationService->findProductConfigFromText($row["description"]);
                    $productCategory = $productConfig ? $productConfig["productCategory"] : null;

                    $row["description"] = $productService->generateProductLabel(
                        $productCategory,
                        $row["length_required"],
                        null, //precise_length todo
                        $row["width_required"],
                        null, //precise_width todo
                        null,
                        null, //precise_height todo
                        $row["grade"],
                        $row["surface"],
                        null, //wall todo
                        null, //kg_per_m todo
                        $row["material"] ?? null,
                    );
                }

                /*
                 * Find general product matches
                 */
                $generalProductMatches = $dataClassificationService->findGeneralProductMatchesFromText(
                    $row["description"],
                    $project->user
                );
                //todo pass through with "allFields" etc

                /*
                 * Check for user-custom products.
                 */
                $customProductMatches = $dataClassificationService->findCustomProductMatches(
                    $row["description"],
                    $project->user,
                );
                //todo make like "findGeneralProductMatchesFromText" above with "allFields" etc

                /*
                 * Append to row
                 */
                $append = $row;
                $append["generalProductMatches"] = $generalProductMatches["results"];
                $append["customProductMatches"] = $customProductMatches;
                $rowDataWithGeneralProductMatches[] = $append;
            }

            //Save user material list
            $this->saveRawMaterialQuoteData(
                $rowDataWithGeneralProductMatches,
                $project,
            );
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
                //dd(2,$detectTableInstances);
                if(count($detectTableInstances) > 0){
                    foreach($detectTableInstances as $table){
                        $tables[] = $table;
                    }
                }
            }
        }

        return $tables;
    }

    public function detectTable(array $csvArray, array $csvRow, int $index, array $tableOptions): ?array
    {
        /**
         * Single purpose: detect table within this document based on heading row match
         */
        $detectTableInstances = [];

        foreach($tableOptions as $tableOption){
            $firstDataRowIndex = $this->firstDataRowIndex($csvRow,$index,$tableOption);
            $firstHeadingColumnAbsoluteIndex = $this->firstHeadingColumnAbsoluteIndex($firstDataRowIndex,$tableOption,$csvArray);
            if($firstDataRowIndex){
                $detectTableInstances[] = [
                    "type" => $tableOption["type"],
                    "data" => $this->getTableData($csvArray,$firstDataRowIndex,$firstHeadingColumnAbsoluteIndex,$tableOption),
                ];
            }
        }

        return $detectTableInstances;
    }

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

    public function firstHeadingColumnAbsoluteIndex(?int $firstDataRowIndex, array $tableOption, array $csvArray): int
    {
        $firstHeadingColumnAbsoluteIndex = 0;

        //Table was found
        if($firstDataRowIndex){
            //Get table heading
            $indexOfHeadingRow = $firstDataRowIndex - $tableOption["OffsetFromHeaderToFirstDataRow"];
            $headingRow = $csvArray[$indexOfHeadingRow];
            $firstHeadingLabel = $tableOption["ExpectedHeadingLabels"][0];

            //Get index of 1st heading label
            $firstHeadingColumnAbsoluteIndex = $this->arraySearchCaseInsensitive($firstHeadingLabel, $headingRow);
        }

        return $firstHeadingColumnAbsoluteIndex;
    }

    private function arraySearchCaseInsensitive($needle, $haystack): int
    {
        /**
         * Single purpose: return index of the result
         */
        // Convert both the needle and haystack values to lowercase for comparison
        $lowercaseHaystack = array_map('strtolower', $haystack);
        $needleLowercase = strtolower($needle);

        // Use array_search to find the index
        return array_search($needleLowercase, $lowercaseHaystack);
    }

    public function getTableData(array $csvArray, int $firstDataRowIndex, int $firstHeadingColumnAbsoluteIndex, array $tableOption): array
    {
        /**
         * Single purpose: return the derived table data
         */
        $tableData = [];

        //get specific indexes
        $descriptionColumnAbsoluteIndex = isset($tableOption["DescriptionRelativeOffset"])
            ? ($firstHeadingColumnAbsoluteIndex + $tableOption["DescriptionRelativeOffset"])
            : null;
        $materialColumnAbsoluteIndex = isset($tableOption["MaterialRelativeOffset"])
            ? ($firstHeadingColumnAbsoluteIndex + $tableOption["MaterialRelativeOffset"])
            : null;
        $gradeColumnAbsoluteIndex = isset($tableOption["GradeRelativeOffset"])
            ? ($firstHeadingColumnAbsoluteIndex + $tableOption["GradeRelativeOffset"])
            : null;
        $surfaceColumnAbsoluteIndex = isset($tableOption["SurfaceRelativeOffset"])
            ? ($firstHeadingColumnAbsoluteIndex + $tableOption["SurfaceRelativeOffset"])
            : null;
        $lengthRequiredColumnAbsoluteIndex = isset($tableOption["LengthRelativeOffset"])
            ? ($firstHeadingColumnAbsoluteIndex + $tableOption["LengthRelativeOffset"])
            : null;
        $widthRequiredColumnAbsoluteIndex = isset($tableOption["WidthRelativeOffset"])
            ? ($firstHeadingColumnAbsoluteIndex + $tableOption["WidthRelativeOffset"])
            : null;
        $subQtyColumnAbsoluteIndex = isset($tableOption["SubQtyRelativeOffset"])
            ? ($firstHeadingColumnAbsoluteIndex + $tableOption["SubQtyRelativeOffset"])
            : null;
        $unitRateColumnAbsoluteIndex = isset($tableOption["UnitRateRelativeOffset"])
            ? ($firstHeadingColumnAbsoluteIndex + $tableOption["UnitRateRelativeOffset"])
            : null;

        $nominalUnits = $tableOption["nominalUnits"];

        $skipOrFinishCheckColumnAbsoluteIndex = $firstHeadingColumnAbsoluteIndex + $tableOption['skipOrFinishCheckRelativeOffset'];

        //Loop through CSV
        foreach($csvArray as $index => $csvRow) {
            //If at or below the 1st data row
            if ($index >= $firstDataRowIndex) {
                //End of table rule
                $shouldFinish = $this->shouldFinish($tableOption["isLastDataRow"],$csvArray, $csvRow, $index, $skipOrFinishCheckColumnAbsoluteIndex);
                if($shouldFinish){
                    break;
                }

                //Skip rule
                $shouldSkip = $this->shouldSkip($tableOption["ShouldSkipRow"], $csvRow, $skipOrFinishCheckColumnAbsoluteIndex);

                //Description
                $compoundDescription = $this->decodeCompoundDescription($tableOption["compoundDescription"],$csvRow,$firstHeadingColumnAbsoluteIndex);
                if($compoundDescription){
                    $description = $compoundDescription;
                }
                else{
                    $description = ($descriptionColumnAbsoluteIndex !== null && isset($csvRow[$descriptionColumnAbsoluteIndex]))
                        ? $csvRow[$descriptionColumnAbsoluteIndex]
                        : null;
                }

                if (!$shouldSkip && $description){
                    //Material
                    $material = ($materialColumnAbsoluteIndex !== null && isset($csvRow[$materialColumnAbsoluteIndex]))
                        ? $csvRow[$materialColumnAbsoluteIndex]
                        : null;

                    //Grade
                    $grade = ($gradeColumnAbsoluteIndex !== null && isset($csvRow[$gradeColumnAbsoluteIndex]))
                        ? $csvRow[$gradeColumnAbsoluteIndex]
                        : null;

                    //Surface
                    $surface = ($surfaceColumnAbsoluteIndex !== null && isset($csvRow[$surfaceColumnAbsoluteIndex]))
                        ? $csvRow[$surfaceColumnAbsoluteIndex]
                        : null;

                    //Length required
                    $lengthRequired = ($lengthRequiredColumnAbsoluteIndex !== null && isset($csvRow[$lengthRequiredColumnAbsoluteIndex]))
                        ? $this->normaliseLengthWidthRequired($csvRow[$lengthRequiredColumnAbsoluteIndex],$nominalUnits)
                        : null;

                    //Width required
                    $widthRequired = ($widthRequiredColumnAbsoluteIndex !== null && isset($csvRow[$widthRequiredColumnAbsoluteIndex]))
                        ? $this->normaliseLengthWidthRequired($csvRow[$widthRequiredColumnAbsoluteIndex],$nominalUnits)
                        : null;

                    //Sub qty
                    $subQty = isset($csvRow[$subQtyColumnAbsoluteIndex])
                        ? $this->getSubQty($csvRow[$subQtyColumnAbsoluteIndex])
                        : null;

                    //Unit rate
                    $unitRate = ($unitRateColumnAbsoluteIndex !== null && isset($csvRow[$unitRateColumnAbsoluteIndex]))
                        ? $this->getUnitRateDollars($csvRow[$unitRateColumnAbsoluteIndex])
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
                        "assembly_mark" => $this->getAssemblyMark($tableOption,$csvRow,$csvArray,$firstDataRowIndex,$firstHeadingColumnAbsoluteIndex),
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
        }

        return $isTableHeader;
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

        $removeLetters = preg_replace('/[a-zA-Z]/', '', $quantity);
        $removeCurrencySymbols =preg_replace('/[€£¥₹$¢₱₽₩₦฿]/u', '', $removeLetters);

        return (float) $removeCurrencySymbols;
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

    public function saveRawMaterialQuoteData($rows,$project): array
    {
        /**
         * Single purpose: save BOM row
         */

        //Services
        $dataClassificationService = new DataClassificationService();
        $nestingService = new NestingService();

        $materialList = [];

        foreach($rows as $row){
            $productConfig = $dataClassificationService->findProductConfigFromText($row["description"]);
            $productCategory = $productConfig
                ? $productConfig["productCategory"]
                : null;

            $algo = $productCategory
                ? $nestingService->getNestingLabelsFromProductCategory($productCategory)[0] ?? null
                : null;

            /**
             * Create 'RawMaterialQuote' item
             */
            $lengthRequired = $row["length_required"] ?? null;
            $widthRequired = $row["width_required"] ?? null;

            $rawMaterialQuote = RawMaterialQuote::create([
                "csv_index" => $row["index"],
                "description" => $row["description"],
                "product_category" => $productCategory,
                "material" => $row["material"] ?? null,
                "grade" => $row["grade"] ?? null,
                "surface" => $row["surface"] ?? null,
                "nominal_units" => MeasurementUnitEnums::MILLIMETERS,
                "length_required" => $this->normalisedLength($algo,$lengthRequired),
                "width_required" => $this->normalisedWidth($algo,$widthRequired),
                "sub_qty" => $row["sub_qty"],
                "unit_rate" => $row["unit_rate"] ?? null,
                'project_id' => $project->id,
                "general_product_matches" => serialize($row["generalProductMatches"]->toArray()),
                "custom_product_matches" => serialize($row["customProductMatches"]),
                "assembly_mark" => $row["assembly_mark"] ?? "",
            ]);

            $materialList[] = $rawMaterialQuote;

            /**
             * Create 'Pieces'
             */
            $allFields = true; //todo complete this properly
            if(count($row["generalProductMatches"]) === 1 && $allFields){
                $item = $row["generalProductMatches"][0];
                $lengthRequired = $row["length_required"] ?? null;
                $widthRequired = $row["width_required"] ?? null;
                $piece = Piece::create([
                    'project_id' => $project->id,
                    "raw_material_quote_id" => $rawMaterialQuote->id,
                    "product_category" => $item["product_category"],
                    "material" => $item["material"],
                    "grade" => $item["grade"],
                    "surface" => $item["surface"],
                    "nominal_units" => $item["nominal_units"],
                    "nesting_algo" => $algo,
                    "nominal_length" => $item["nominal_length"] ?? null,
                    "precise_length" => $item["precise_length"] ?? null,
                    "nominal_width" => $item["nominal_width"] ?? null,
                    "precise_width" => $item["precise_width"] ?? null,
                    "nominal_height" => $item["nominal_height"] ?? null,
                    "precise_height" => $item["precise_height"] ?? null,
                    "actual_length" => $this->normalisedLength($algo,$lengthRequired),
                    "actual_width" => $this->normalisedWidth($algo,$widthRequired),
                    "wall" => $item["wall"] ?? null,
                    "kg_per_m" => $item["kg_per_m"] ?? null,
                    "actual_qty" => $row["sub_qty"],
                ]);
            }
        }

        return $materialList;
    }

    public function normalisedLength(?string $algo, ?float $lengthRequired): ?float
    {
        /**
         * Single purpose: convert M to MM, or keep MM as MM depending on how it looks.
         * "length" for Bundle items like bolts is more likely a QTY multiplier, so leave it as null since sub qty will capture it
         */

        $normalisedLength = null;

        if($lengthRequired){
            //Algo
            if($algo){
                //Bundle
                if($algo === NestingEnums::BUNDLE->value){
                    //more likely a QTY multiplier, so leave it as null since sub qty will capture it
                    $normalisedLength = 1; //default. Will be ignored in the tables
                }
                //Other algos
                else{
                    $normalisedLength = ($lengthRequired && $lengthRequired < 20) ? ($lengthRequired*1000) : $lengthRequired;
                }
            }
            else{
                //todo check this
                $normalisedLength = $lengthRequired;
            }
        }

        return $normalisedLength;
    }

    public function normalisedWidth(?string $algo, ?float $widthRequired): ?float
    {
        /**
         * Single purpose: convert M to MM, or keep MM as MM depending on how it looks.
         * "length" for Bundle items like bolts is more likely a QTY multiplier
         */

        $normalisedWidth = null;

        if($widthRequired){
            //Algo
            if($algo){
                //Bundle
                if($algo === NestingEnums::BUNDLE->value){
                    //more likely a QTY multiplier, so leave it as null since sub qty will capture it
                }
                //Other algos
                else{
                    $normalisedWidth = ($widthRequired < 20) ? ($widthRequired*1000) : $widthRequired;
                }
            }
            else{
                $normalisedWidth = $widthRequired;
            }
        }

        return $normalisedWidth;
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

    public function getAssemblyMark(array $tableOption, array $csvRow, array $csvArray, int $firstDataRowIndex, int $firstHeadingColumnAbsoluteIndex): ?string
    {
        /**
         * Get an assembly mark (reference) for each CSV row (if available).
         * 1) Directly from a column for each row
         * 2) A fixed cell reference applied to all rows.
         *    Relative coordinates relative to first table heading. up & left = minus. e.g X,Y = [3,-1]
         * 3) None available
         */

        $assemblyMark = "";

        //1) Directly from a column for each row
        if($tableOption["assemblyMarkRule"][0] === "COLUMN"){
            $assemblyMark = $this->assemblyMarkRuleColumn($firstHeadingColumnAbsoluteIndex,$tableOption["assemblyMarkRule"][1],$csvRow);
        }

        //2) A fixed cell reference applied to all rows
        if($tableOption["assemblyMarkRule"][0] === "FIXED"){
            $assemblyMark = $this->assemblyMarkRuleFixed(
                $tableOption["assemblyMarkRule"][1],
                $csvArray,
                $firstDataRowIndex,
                $firstHeadingColumnAbsoluteIndex,
                $tableOption,
            );
        }

        return $assemblyMark;
    }

    private function assemblyMarkRuleColumn(int $firstHeadingColumnAbsoluteIndex, int $relativeOffset, array $csvRow): ? string
    {

        $columnIndex = $firstHeadingColumnAbsoluteIndex + $relativeOffset - 1;

        return $csvRow[$columnIndex];
    }

    private function assemblyMarkRuleFixed(array $relativeCoordinates, array $csvArray, int $firstDataRowIndex, int $firstHeadingColumnAbsoluteIndex, array $tableOption): ? string
    {
        /**
         * Relative coordinates relative to first table heading. up & left = minus. e.g X,Y = [3,-1]
         */
        $indexOfHeadingRow = $firstDataRowIndex - $tableOption["OffsetFromHeaderToFirstDataRow"];

        $newX = $firstHeadingColumnAbsoluteIndex + $relativeCoordinates[0];
        $newY = $indexOfHeadingRow + $relativeCoordinates[1];

        return $csvArray[$newY][$newX];
    }

    private function decodeCompoundDescription(?array $compoundDescription, array $csvRow, int $firstHeadingColumnAbsoluteIndex): ?string
    {
        /**
         * Decode: "M##Bolt Dia##Bolt Grade##Length(mm)"
         * see "compoundDescription" variables in TableTemplates.php
         */
        $decodeCompoundDescription = null;

        if($compoundDescription){
            $prefix = $compoundDescription["prefix"] ?? "";
            $decodeCompoundDescription = $prefix;
            foreach($compoundDescription["relativeOffsets"] as $index => $offset){
                $decodeCompoundDescription = $decodeCompoundDescription.($index > 0 ? ' ' : '').$csvRow[$firstHeadingColumnAbsoluteIndex + $offset];
            }
            $suffix = $compoundDescription["suffix"] ?? "";
            $decodeCompoundDescription = $decodeCompoundDescription.$suffix;
        }

        return $decodeCompoundDescription;
    }
}
