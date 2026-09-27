<?php

namespace App\Services;

use App\Enums\MeasurementUnitEnums;
use App\Enums\NestingEnums;
use App\Formatters\NestingFormatter;
use App\Models\Business;
use App\Models\Project;
use App\Models\RawMaterialQuote;
use App\Models\User;
use App\Notifications\AdminUnfoundItems;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Notification;

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
        $detectedTables = $this->detectedTables($csvArray, $eligibleTables);

        //Should have at least 1 result
        if (count($detectedTables) > 0) {
            //Process data
            $this->processTemplate($detectedTables, $project);

            //Return back with project ID
            $return = back()->with("project",$project);
        } else {
            $return = back()->with('warning', $errorMsg);
        }

        return $return;
    }

    public function validateTemplateExists(array $csvArray): bool
    {
        $templateExists = false;

        //Eligible Tables
        $eligibleTables = $this->eligibleTables();

        //Detected Tables
        $detectedTables = $this->detectedTables($csvArray, $eligibleTables);

        //Should have at least 1 result
        if (count($detectedTables) > 0) {
            $templateExists = true;
        }

        return $templateExists;
    }

    public function eligibleTables(): array
    {
        //Users
        $authUser = auth()->user();

        $eligibleTables = [];
        foreach (config('TableTemplates') as $table) {
            $ownerDomain = $table['ownerDomain'];

            if ($this->isEligibleForThisTable($authUser, $ownerDomain)) {
                $eligibleTables[] = $table;
            }
        }

        return $eligibleTables;
    }

    public function isEligibleForThisTable(object $authUser, ?string $domain): bool
    {
        /**
         * Single purpose: check if this template specifically is eligible for this user
         */

        //For everybody
        $condition_1 = $domain === null;

        //Is admin (sees everything)
        $condition_2 = $authUser->isAdmin();

        //For this business ($domain is nullable, and condition_1 already covers null)
        $condition_3 = $domain !== null
            && strtoupper((string) $authUser->getDomainFromEmail()) === strtoupper($domain);

        return $condition_1 || $condition_2 || $condition_3;
    }

    public function processTemplate(array $detectedTables, Project $project): void
    {
        /**
         * Single purpose: extract the materials from all the tables detected
         */

        //Services
        $dataClassificationService = new DataClassificationService;

        //Prerequisite variables
        $user = $project->user;
        $business = $user->business;

        foreach ($detectedTables as $tableInstance) {
            $rows = $tableInstance['data'];

            //Sense checks
            //$productService->senseChecks(); //todo incomplete

            /*
             * A "derive a description when the column is blank" branch used to sit at the
             * top of this loop. getTableData() never emits a row with a blank description
             * (it is the condition for keeping the row at all), so it was unreachable -
             * and had it ever run it fed that same blank description to the classifier,
             * so the category it derived the label from was always null. Templates with
             * no description column use "compoundDescription" instead, which is built in
             * getTableData() where the sheet is still in reach.
             */
            $rowDataWithGeneralProductMatches = [];
            foreach ($rows as $row) {
                /*
                 * Find general product matches
                 */
                $generalProductMatches = $dataClassificationService->findGeneralProductMatchesFromText(
                    $row['description'],
                    $project->user,
                );

                /*
                 * Check for user-custom products.
                 */
                $customProductMatches = $dataClassificationService->findCustomProductMatches(
                    $row['description'],
                    $project->user,
                );

                /*
                 * Append to row
                 */
                $append = $row;
                $append['generalProductMatches'] = $generalProductMatches;
                $append['customProductMatches'] = $customProductMatches;
                $rowDataWithGeneralProductMatches[] = $append;
            }

            //Save user material list
            $this->saveRawMaterialQuoteData(
                $rowDataWithGeneralProductMatches,
                $project,
                $business,
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
        foreach ($csvArray as $index => $csvRow) {
            $blankRow = count(array_filter($csvRow, function ($value) {
                return $value !== null;
            })) === 0;
            if (! $blankRow) {
                $detectTableInstances = $this->detectTable($csvArray, $csvRow, $index, $eligibleTables);
                //dd(2,$detectTableInstances);
                if (count($detectTableInstances) > 0) {
                    foreach ($detectTableInstances as $table) {
                        $tables[] = $table;
                    }
                }
            }
        }

        return $tables;
    }

    public function detectTable(array $csvArray, array $csvRow, int $index, array $tableOptions): array
    {
        /**
         * Single purpose: detect table within this document based on heading row match
         */
        $detectTableInstances = [];

        foreach ($tableOptions as $tableOption) {
            $headerStartIndex = $this->headerStartIndex($csvRow, $tableOption);

            //Not this template's heading row
            if ($headerStartIndex === null) {
                continue;
            }

            /*
             * Read straight off the row that matched. This used to be derived a second
             * time by searching the heading row again for the first label, through a
             * helper whose "not found" answer was array_search()'s false - quietly
             * coerced to column 0 by its int return type, so every offset in the table
             * was measured from the wrong origin.
             */
            $firstDataRowIndex = $index + $tableOption['OffsetFromHeaderToFirstDataRow'];

            $detectTableInstances[] = [
                'type' => $tableOption['type'],
                'data' => $this->getTableData($csvArray, $firstDataRowIndex, $headerStartIndex, $tableOption),
            ];
        }

        return $detectTableInstances;
    }

    public function headerStartIndex(array $csvRow, array $tableOption): ?int
    {
        /**
         * Single purpose: the column the template's heading labels start at, or null
         * if this row is not that heading. Labels must appear in order, though not
         * necessarily side by side.
         */
        $expectedHeadingLabels = $tableOption['ExpectedHeadingLabels'];

        //Blank cells arrive as null, which strtoupper() no longer accepts directly
        $upperRow = array_map(fn ($columnValue) => strtoupper((string) $columnValue), $csvRow);

        /*
         * Cheap check before walking the row in order, which is the expensive part
         */
        if (! in_array(strtoupper($expectedHeadingLabels[0]), $upperRow, true)) {
            return null;
        }

        $startIndex = null;
        $labelIndex = 0;

        foreach ($upperRow as $columnIndex => $columnValue) {
            if (! isset($expectedHeadingLabels[$labelIndex])) {
                break;
            }

            if ($columnValue === strtoupper($expectedHeadingLabels[$labelIndex])) {
                //Remember where the run began - that is the origin every offset is measured from
                if ($labelIndex === 0) {
                    $startIndex = $columnIndex;
                }

                $labelIndex++;

                //All labels matched, in order
                if ($labelIndex === count($expectedHeadingLabels)) {
                    return $startIndex;
                }
            }
        }

        return null;
    }

    public function isTableHeader(array $csvRow, array $tableOption): bool
    {
        /**
         * Single purpose: confirm if this CSV row matches a known table header
         */
        return $this->headerStartIndex($csvRow, $tableOption) !== null;
    }

    public function getTableData(array $csvArray, int $firstDataRowIndex, int $firstHeadingColumnAbsoluteIndex, array $tableOption): array
    {
        /**
         * Single purpose: return the derived table data
         */
        $tableData = [];

        //get specific indexes
        $descriptionColumnAbsoluteIndex = isset($tableOption['DescriptionRelativeOffset'])
            ? ($firstHeadingColumnAbsoluteIndex + $tableOption['DescriptionRelativeOffset'])
            : null;
        $materialColumnAbsoluteIndex = isset($tableOption['MaterialRelativeOffset'])
            ? ($firstHeadingColumnAbsoluteIndex + $tableOption['MaterialRelativeOffset'])
            : null;
        $gradeColumnAbsoluteIndex = isset($tableOption['GradeRelativeOffset'])
            ? ($firstHeadingColumnAbsoluteIndex + $tableOption['GradeRelativeOffset'])
            : null;
        $surfaceColumnAbsoluteIndex = isset($tableOption['SurfaceRelativeOffset'])
            ? ($firstHeadingColumnAbsoluteIndex + $tableOption['SurfaceRelativeOffset'])
            : null;
        $lengthRequiredColumnAbsoluteIndex = isset($tableOption['LengthRelativeOffset'])
            ? ($firstHeadingColumnAbsoluteIndex + $tableOption['LengthRelativeOffset'])
            : null;
        $widthRequiredColumnAbsoluteIndex = isset($tableOption['WidthRelativeOffset'])
            ? ($firstHeadingColumnAbsoluteIndex + $tableOption['WidthRelativeOffset'])
            : null;
        $subQtyColumnAbsoluteIndex = isset($tableOption['SubQtyRelativeOffset'])
            ? ($firstHeadingColumnAbsoluteIndex + $tableOption['SubQtyRelativeOffset'])
            : null;

        $nominalUnits = $tableOption['nominalUnits'];

        $skipOrFinishCheckColumnAbsoluteIndex = $firstHeadingColumnAbsoluteIndex + $tableOption['skipOrFinishCheckRelativeOffset'];

        //Loop through CSV
        foreach ($csvArray as $index => $csvRow) {
            //If at or below the 1st data row
            if ($index >= $firstDataRowIndex) {
                //End of table rule
                $shouldFinish = $this->shouldFinish($tableOption['isLastDataRow'], $csvArray, $csvRow, $index, $skipOrFinishCheckColumnAbsoluteIndex);
                if ($shouldFinish) {
                    break;
                }

                //Skip rule
                $shouldSkip = $this->shouldSkip($tableOption['ShouldSkipRow'], $csvRow, $skipOrFinishCheckColumnAbsoluteIndex);

                //Description
                $compoundDescription = $this->decodeCompoundDescription($tableOption['compoundDescription'], $csvRow, $firstHeadingColumnAbsoluteIndex);
                if ($compoundDescription) {
                    $description = $compoundDescription;
                } else {
                    $description = ($descriptionColumnAbsoluteIndex !== null && isset($csvRow[$descriptionColumnAbsoluteIndex]))
                        ? $csvRow[$descriptionColumnAbsoluteIndex]
                        : null;
                }

                if (! $shouldSkip && $description) {
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
                        ? $this->normaliseLengthWidthRequired($csvRow[$lengthRequiredColumnAbsoluteIndex], $nominalUnits)
                        : null;

                    //Width required
                    $widthRequired = ($widthRequiredColumnAbsoluteIndex !== null && isset($csvRow[$widthRequiredColumnAbsoluteIndex]))
                        ? $this->normaliseLengthWidthRequired($csvRow[$widthRequiredColumnAbsoluteIndex], $nominalUnits)
                        : null;

                    //Sub qty
                    $subQty = isset($csvRow[$subQtyColumnAbsoluteIndex])
                        ? $this->getSubQty($csvRow[$subQtyColumnAbsoluteIndex])
                        : null;

                    $tableData[] = [
                        'index' => $index,
                        'description' => $description,
                        'material' => $material,
                        'grade' => $grade,
                        'surface' => $surface,
                        'length_required' => $lengthRequired,
                        'width_required' => $widthRequired,
                        'sub_qty' => $subQty,
                        'assembly_mark' => $this->getAssemblyMark($tableOption, $csvRow, $csvArray, $firstDataRowIndex, $firstHeadingColumnAbsoluteIndex),
                    ];
                }
            }
        }

        return $tableData;
    }

    public function getSubQty(?string $rawSubQty): float
    {
        /**
         * Single purpose: convert sub qty to a float. Also set "1" as default to avoid zero multiplication
         */
        $float = (float) $rawSubQty;

        return $float === 0.0 ? 1.0 : $float;
    }

    public function normaliseLengthWidthRequired(?string $quantity, string $lengthWidthUnits): float
    {
        /**
         * Single purpose: extract just the number from string number representation.
         *
         * $lengthWidthUnits is every template's "nominalUnits", and it is deliberately
         * not used here. Converting by the declared unit and then letting
         * normalisedLength() apply its "below 20 must be meters" rule would convert
         * twice: 0.015 in a meters column would become 15mm, then 15000mm. The two
         * rules cannot both be authoritative, and which one wins is a decision about
         * real steel, not a tidy-up. See normalisedLength().
         */
        $removeLetters = preg_replace('/[a-zA-Z]/', '', (string) $quantity);
        $removeCurrencySymbols = preg_replace('/[€£¥₹$¢₱₽₩₦฿]/u', '', $removeLetters);

        return (float) $removeCurrencySymbols;
    }

    public function saveRawMaterialQuoteData(array $rows, Project $project, Business $business): array
    {
        /**
         * Single purpose: save BOM row.
         * Note: "rows" at this point contains ALL rows imported
         */

        //Formatter
        $dataClassificationService = new DataClassificationService;
        $nestingFormatter = new NestingFormatter();
        $pieceService = new PieceService;

        $materialList = [];
        $itemsNotFound = [];
        $fromOtherPlan = [];
        $couldNotBeRead = [];
        foreach ($rows as $row) {
            /*
             * One unusable row used to take the rest of the file down with it. The
             * exception unwound this whole loop, every row below it was lost without
             * a trace, and the user was told the template had stopped auto-detecting.
             * Each row now fails on its own and is reported back by name.
             */
            try {
                //Find matching product config
                $productConfig = $dataClassificationService->findProductConfigFromText($row['description']);

                if(!$productConfig){
                    continue; //don't save this row
                }

                //Product category
                $productCategory = $productConfig['productCategory'];

                //Algo
                $algo = $productCategory
                    ? $nestingFormatter->getNestingLabelsFromProductCategory($productCategory)[0] ?? null
                    : null;

                //Supplier group belongs to current plan
                $supplierGroup = $productConfig['supplierGroup']->value;
                if (! $business->supplierGroupIsCurrentPlan($supplierGroup)) {
                    $fromOtherPlan[] = $row['description'];
                    continue; //don't save this row
                }

                //Business plan is meterage products only
                if($business->meterage_only){
                    if($algo !== NestingEnums::METERAGE->value){
                        $fromOtherPlan[] = $row['description'];
                        continue; //don't save this row
                    }
                }

                //Must have general product matches
                if(count($row['generalProductMatches']["results"]) === 0){
                    $itemsNotFound[] = $row['description'];
                    continue; //don't save this row
                }

                /**
                 * Create 'RawMaterialQuote' item
                 */
                $lengthRequired = $this->normalisedLength($algo, $row['length_required'] ?? null);
                $widthRequired = $this->normalisedWidth($algo, $row['width_required'] ?? null);

                /*
                 * Nesting can do nothing with a row whose length never made it off the
                 * sheet. A blank cell, a dash, an "N/A" and a formula that arrived as
                 * "#REF!" all reduce to 0 here, and length_required is NOT NULL - so
                 * this was the QueryException that used to end the import. Say which
                 * line it was instead.
                 */
                if ($lengthRequired === null) {
                    $couldNotBeRead[] = $row['description'];
                    continue; //don't save this row
                }

                /*
                 * A template with no SubQty column leaves this null against a NOT NULL
                 * column. One is what getSubQty() already substitutes for a zero, for
                 * the same reason: a missing multiplier must not zero the quantity.
                 */
                $subQty = $row['sub_qty'] ?? 1.0;

                $rawMaterialQuote = RawMaterialQuote::create([
                    'csv_index' => $row['index'],
                    'description' => $row['description'],
                    'product_category' => $productCategory,
                    'material' => $row['material'] ?? null,
                    'grade' => $row['grade'] ?? null,
                    'surface' => $row['surface'] ?? null,
                    'nominal_units' => MeasurementUnitEnums::MILLIMETERS,
                    'length_required' => $lengthRequired,
                    'width_required' => $widthRequired,
                    'sub_qty' => $subQty,
                    'project_id' => $project->id,
                    'general_product_matches' => serialize($row['generalProductMatches']),
                    'custom_product_matches' => serialize($row['customProductMatches']),
                    'assembly_mark' => $row['assembly_mark'] ?? '',
                ]);

                $materialList[] = $rawMaterialQuote;

                /**
                 * Create 'Pieces'
                 */
                if (count($row['generalProductMatches']['results']) === 1 && $algo) {
                    $productSpec = $row['generalProductMatches']['results'][0];
                    $piece = $pieceService->createPieceFromProductSpec($productSpec, $rawMaterialQuote, $algo);
                }
            }
            /*
             * Anything this row does that we did not anticipate. The row is lost either
             * way, but the rest of the file is not, and the bug is reported rather than
             * being dressed up as a template problem.
             */
            catch (\Throwable $e) {
                report($e);

                $couldNotBeRead[] = $row['description'];
            }
        }

        /**
         * Notify user & admin of items not found
         */
        if(count($itemsNotFound) > 0 || count($fromOtherPlan) > 0 || count($couldNotBeRead) > 0){
            //Notify admin
            $adminUser = User::query()->where('email', config('env.admin_email'))->first();
            if ($adminUser && count($itemsNotFound) > 0) {
                $message = "The following items were not found: ".implode(", ",$itemsNotFound);
                Notification::send($adminUser, new AdminUnfoundItems($message));
            }

            //Notify user
            $project->recordUnimportedItems($itemsNotFound, $fromOtherPlan, $couldNotBeRead);
        }

        /**
         * Anything this upload did import stops being an outstanding item, however
         * many earlier uploads left it on the list.
         */
        $project->forgetImportedItems();

        return $materialList;
    }

    public function normalisedLength(?string $algo, ?float $lengthRequired): ?float
    {
        /**
         * Single purpose: convert M to MM, or keep MM as MM depending on how it looks.
         * "length" for Bundle items like bolts is more likely a QTY multiplier, so leave it as null since sub qty will capture it
         *
         * Known limitation: the rule is "below 20 must be meters", applied whatever the
         * template says its units are. It is right for a sheet that mixes meters and
         * millimeters in one column, and wrong for a genuine sub-20mm part in a column
         * already in millimeters - 15 becomes 15000. Templates carry a "nominalUnits"
         * that could settle this, but only once someone decides which signal wins.
         */
        $normalisedLength = null;

        if ($lengthRequired) {
            //Algo
            if ($algo) {
                //Bundle
                if ($algo === NestingEnums::BUNDLE->value) {
                    //more likely a QTY multiplier, so leave it as null since sub qty will capture it
                    $normalisedLength = 1; //default. Will be ignored in the tables
                }
                //Other algos
                else {
                    $normalisedLength = ($lengthRequired && $lengthRequired < 20) ? ($lengthRequired * 1000) : $lengthRequired;
                }
            } else {
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

        if ($widthRequired) {
            //Algo
            if ($algo) {
                //Bundle
                if ($algo === NestingEnums::BUNDLE->value) {
                    //more likely a QTY multiplier, so leave it as null since sub qty will capture it
                }
                //Other algos
                else {
                    $normalisedWidth = ($widthRequired < 20) ? ($widthRequired * 1000) : $widthRequired;
                }
            } else {
                $normalisedWidth = $widthRequired;
            }
        }

        return $normalisedWidth;
    }

    public function shouldFinish(?string $text, array $csvArray, array $csvRow, int $index, int $skipOrFinishCheckColumnIndex): bool
    {
        $shouldFinish = false;

        //Has text
        if ($text) {
            $shouldFinish = $this->isLastDataRowTextContains($text, $csvRow, $skipOrFinishCheckColumnIndex);
        } else {
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
        if (isset($csvArray[$index][$skipOrFinishCheckColumnIndex])) {
            $thisCell = $csvArray[$index][$skipOrFinishCheckColumnIndex];
            $thisCellBlank = $thisCell == '' || $thisCell == null;
        }

        //Next row exists
        $nextCellBlank = true;
        if (isset($csvArray[$index + 1][$skipOrFinishCheckColumnIndex])) {
            $nextCell = $csvArray[$index + 1][$skipOrFinishCheckColumnIndex];
            $nextCellBlank = $nextCell == '' || $nextCell == null;
        }

        return $thisCellBlank && $nextCellBlank;
    }

    public function isLastDataRowTextContains(string $text, array $csvRow, int $skipOrFinishCheckColumnIndex): bool
    {
        /**
         * Description cell contains specific
         */
        $result = false;

        if (isset($csvRow[$skipOrFinishCheckColumnIndex])) {
            $result = strtoupper($csvRow[$skipOrFinishCheckColumnIndex]) === strtoupper($text);
        }

        return $result;
    }

    public function shouldSkip(?string $text, array $csvRow, int $skipOrFinishCheckColumnIndex): bool
    {
        $shouldSkip = false;

        //Blank row
        $blankRow = count(array_filter($csvRow, function ($value) {
            return $value !== null;
        })) === 0;
        if ($blankRow) {
            $shouldSkip = true;
        } else {
            //Has text
            if ($text) {
                $shouldSkip = $this->shouldSkipRowDescriptionTextContains($text, $csvRow, $skipOrFinishCheckColumnIndex);
            } else {
                $shouldSkip = $this->shouldSkipRowBlankDescription($csvRow, $skipOrFinishCheckColumnIndex);
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

        if (isset($csvRow[$descriptionColumnIndex])) {
            $skip = $csvRow[$descriptionColumnIndex] === '';
        }

        return $skip;
    }

    public function shouldSkipRowDescriptionTextContains(string $text, array $csvRow, int $descriptionColumnIndex): bool
    {
        /**
         * Description cell contains specific
         *
         * A row shorter than the check column - a trailing part-filled row, say - used
         * to raise "Undefined array key" and then pass null to strtoupper(), which is a
         * fatal in PHP 9. Its two sibling rules already guarded this.
         */
        if (! isset($csvRow[$descriptionColumnIndex])) {
            return false;
        }

        return strtoupper((string) $csvRow[$descriptionColumnIndex]) === strtoupper($text);
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
        $assemblyMark = '';

        //1) Directly from a column for each row
        if ($tableOption['assemblyMarkRule'][0] === 'COLUMN') {
            $assemblyMark = $this->assemblyMarkRuleColumn($firstHeadingColumnAbsoluteIndex, $tableOption['assemblyMarkRule'][1], $csvRow);
        }

        //2) A fixed cell reference applied to all rows
        if ($tableOption['assemblyMarkRule'][0] === 'FIXED') {
            $assemblyMark = $this->assemblyMarkRuleFixed(
                $tableOption['assemblyMarkRule'][1],
                $csvArray,
                $firstDataRowIndex,
                $firstHeadingColumnAbsoluteIndex,
                $tableOption,
            );
        }

        return $assemblyMark;
    }

    private function assemblyMarkRuleColumn(int $firstHeadingColumnAbsoluteIndex, int $relativeOffset, array $csvRow): ?string
    {
        /**
         * The offset is the column NUMBER the rule gives (1 = the first heading column),
         * hence the -1 onto a zero-based index.
         */
        $columnIndex = $firstHeadingColumnAbsoluteIndex + $relativeOffset - 1;

        //A row that stops short of this column is not a reason to lose the row
        return isset($csvRow[$columnIndex]) ? (string) $csvRow[$columnIndex] : null;
    }

    private function assemblyMarkRuleFixed(array $relativeCoordinates, array $csvArray, int $firstDataRowIndex, int $firstHeadingColumnAbsoluteIndex, array $tableOption): ?string
    {
        /**
         * Relative coordinates relative to first table heading. up & left = minus. e.g X,Y = [3,-1]
         */
        $indexOfHeadingRow = $firstDataRowIndex - $tableOption['OffsetFromHeaderToFirstDataRow'];

        $newX = $firstHeadingColumnAbsoluteIndex + $relativeCoordinates[0];
        $newY = $indexOfHeadingRow + $relativeCoordinates[1];

        //Coordinates can land outside the sheet: an assembly mark is not worth the row
        return isset($csvArray[$newY][$newX]) ? (string) $csvArray[$newY][$newX] : null;
    }

    private function decodeCompoundDescription(?array $compoundDescription, array $csvRow, int $firstHeadingColumnAbsoluteIndex): ?string
    {
        /**
         * Decode: "M##Bolt Dia##Bolt Grade##Length(mm)"
         * see "compoundDescription" variables in TableTemplates.php
         */
        $decodeCompoundDescription = null;

        if ($compoundDescription) {
            $prefix = $compoundDescription['prefix'] ?? '';
            $decodeCompoundDescription = $prefix;
            foreach ($compoundDescription['relativeOffsets'] as $index => $offset) {
                //A short row contributes nothing rather than raising "Undefined array key"
                $cell = $csvRow[$firstHeadingColumnAbsoluteIndex + $offset] ?? '';

                $decodeCompoundDescription = $decodeCompoundDescription.($index > 0 ? ' ' : '').$cell;
            }
            $suffix = $compoundDescription['suffix'] ?? '';
            $decodeCompoundDescription = $decodeCompoundDescription.$suffix;
        }

        return $decodeCompoundDescription;
    }
}
