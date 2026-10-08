<?php

namespace App\Services;

use App\Enums\MeasurementUnitEnums;
use App\Enums\NestingEnums;
use App\Formatters\NestingFormatter;
use App\Models\Business;
use App\Models\MaterialListFile;
use App\Models\Project;
use App\Models\RawMaterialQuote;
use App\Models\Template;
use App\Models\User;
use App\Notifications\AdminUnfoundItems;
use Illuminate\Support\Facades\Notification;

class CsvService
{
    /*
     * How far past a blank check cell to look for the table carrying on. Wide enough to step over
     * the spacer rows and the group headings a grouped report puts between its bands, narrow enough
     * that the next thing down the sheet is not read as part of this table.
     */
    private const FINISH_LOOKAHEAD_ROWS = 6;

    /*
     * How many rows together count as the table carrying on rather than as a footer line. A
     * materials table is a run of rows; "End of report" and a total are one line each.
     */
    private const FINISH_RESUMES_AFTER_ROWS = 2;

    /**
     * Whether a check cell's text reads as a material, by the text itself. One classifier and one
     * answer per distinct string: the end-of-table rule asks this of the same handful of footer
     * lines over and over, and findProductConfigFromText() scores every product category each time.
     *
     * @var array<string, bool>
     */
    private array $readsAsMaterial = [];

    /**
     * Every table this user's templates find in an uploaded sheet.
     *
     * This was processCsv(), which detected, imported and then built the RedirectResponse for both
     * outcomes. Detecting is now asked on its own because the answer "nothing matched" has stopped
     * being the end of the story: ProductController takes that answer to TemplateLearningService,
     * which writes a template for the file if it can, and then asks this again. A method that
     * imported and redirected in the same breath had nowhere to put that step.
     *
     * validateTemplateExists() was deleted alongside it. It was this question with the answer
     * flattened to a bool, and nothing had called it since templates started driving detection.
     *
     * @param  array<int, array<int, mixed>>  $csvArray
     * @param  User|null  $user  Whose templates to match against. Null means whoever is signed in,
     *                           which is right for an upload and wrong wherever the file belongs to
     *                           somebody else - a colleague's project, uploaded on their behalf. The
     *                           two only ever differ inside one business today, so this is here to
     *                           stop that being load bearing.
     * @return array<int, array<string, mixed>>
     */
    public function detectTables(array $csvArray, ?User $user = null): array
    {
        return $this->detectedTables($csvArray, $this->eligibleTables($user));
    }

    public function eligibleTables(?User $user = null): array
    {
        /**
         * Single purpose: the templates this user's uploads are matched against.
         *
         * These used to be the entries in config/TableTemplates.php, filtered by comparing the
         * user's email domain to each entry's "ownerDomain". They are now rows an admin records
         * on the templates screen, filtered by who the row belongs to - so adding a customer's
         * spreadsheet is a form submission rather than a deploy.
         *
         * detectionSpec() hands back the same array shape the config did, which is why nothing
         * below this line had to change. See Template::detectionSpec().
         */
        return Template::query()
            ->eligibleFor($user ?? auth()->user())
            ->detectable()
            //Detection order decides which template a file reports as matching first
            ->orderBy('id')
            ->get()
            ->map(fn (Template $template) => $template->detectionSpec())
            /*
             * detectable() asks the two questions a query can - is there an anchor, are there
             * labels - and detectionSpec() asks the third, which needs the cells read: a row with
             * no cell reference at all has no first row of data and describes no table. It cannot
             * be saved through the form, but it can be written directly, and an empty spec reaching
             * headerStartIndex() is an "undefined array key" on a customer's upload.
             */
            ->filter(fn (array $spec) => $spec !== [])
            ->values()
            ->all();
    }

    /**
     * @param  MaterialListFile|null  $materialListFile  The upload these tables were detected in, so
     *                                                   every row it produces can be traced back to
     *                                                   it and the whole file taken off again as one
     *                                                   thing. Null only where there is no file to
     *                                                   name - the example lists, and the tests that
     *                                                   import an array directly.
     */
    public function processTemplate(
        array $detectedTables,
        Project $project,
        ?MaterialListFile $materialListFile = null,
    ): void {
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
                 * Find general product matches, with the row's own material and grade columns.
                 *
                 * They used to be read off the sheet, stored on the row and then read by nothing:
                 * matching was done on the description alone, so a Tekla export carrying "310UB40"
                 * in Profile and "GRADE 350" in Grade was matched against the catalogue with no
                 * grade at all.
                 *
                 * They fill gaps rather than overrule, and the surface column is passed but not
                 * matched on - see DataClassificationService::findGeneralProductMatchesFromText(),
                 * which is where both of those decisions are written down.
                 */
                $generalProductMatches = $dataClassificationService->findGeneralProductMatchesFromText(
                    $row['description'],
                    $project->user,
                    [
                        'material' => $row['material'] ?? null,
                        'grade' => $row['grade'] ?? null,
                        'surface' => $row['surface'] ?? null,
                    ],
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
                $materialListFile,
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
        $expectedHeadingLabels = array_map(
            fn ($label) => $this->comparable($label),
            $tableOption['ExpectedHeadingLabels'],
        );

        /*
         * Cheap check before walking the row in order, which is the expensive part. It stops at the
         * first hit and builds nothing, which matters because the end-of-table rule asks this of
         * every data row of every table now - see shouldFinish() - on top of detectedTables()
         * asking it of every row of the sheet.
         */
        $carriesFirstLabel = false;

        foreach ($csvRow as $columnValue) {
            if ($this->comparable($columnValue) === $expectedHeadingLabels[0]) {
                $carriesFirstLabel = true;

                break;
            }
        }

        if (! $carriesFirstLabel) {
            return null;
        }

        //Blank cells arrive as null, which strtoupper() no longer accepts directly
        $upperRow = array_map(fn ($columnValue) => $this->comparable($columnValue), $csvRow);

        /*
         * Every column the first label appears in is tried as the start of the run, and the
         * tightest run wins.
         *
         * One pass that took the leftmost match of the first label and walked forward from there
         * used to be the whole of this. A row of "Mark, Length, Mark, Qty, Length" matched the
         * labels "Mark, Qty, Length" from column A - Mark in A, Qty in D, Length in E, in order,
         * which is what the rule asks for - and answered column A, when the heading run a person
         * reading the sheet would point at starts at column C. The anchor is the origin every
         * column offset is measured from, so answering two columns early shifts the whole table,
         * and every label had matched, so nothing about it looked wrong.
         *
         * Where more than one run is valid the labels are repeated in the row, and the shortest
         * span across them is the heading rather than the accident. A row holding each label once
         * has exactly one run, so this changes nothing for it.
         */
        $startIndex = null;
        $shortestSpan = null;

        foreach ($upperRow as $candidate => $columnValue) {
            if ($columnValue !== $expectedHeadingLabels[0]) {
                continue;
            }

            $end = $this->labelsRunFrom($upperRow, $expectedHeadingLabels, $candidate);

            if ($end === null) {
                continue;
            }

            if ($shortestSpan === null || ($end - $candidate) < $shortestSpan) {
                $startIndex = $candidate;
                $shortestSpan = $end - $candidate;
            }
        }

        return $startIndex;
    }

    /**
     * The column the last label lands in when every label appears in order at or after this
     * column, the first one being on it. Null when they do not.
     *
     * @param  array<int, string>  $upperRow
     * @param  array<int, string>  $labels
     */
    private function labelsRunFrom(array $upperRow, array $labels, int $startIndex): ?int
    {
        $labelIndex = 0;

        foreach ($upperRow as $columnIndex => $columnValue) {
            if ($columnIndex < $startIndex) {
                continue;
            }

            if ($columnValue === $labels[$labelIndex]) {
                $labelIndex++;

                //All labels matched, in order
                if ($labelIndex === count($labels)) {
                    return $columnIndex;
                }
            }
        }

        return null;
    }

    /**
     * A heading cell or a recorded label as the comparison sees it.
     *
     * Labels are compared literally - "Length (mm)" and "Length" are two different reports - and
     * that is deliberate. Leading and trailing space is not part of that: the labels are trimmed
     * on the way into the record, and SpreadsheetGrid trims every value before a sample is read or
     * described, so an untrimmed sheet cell was the one way a template could stop matching the
     * very file it was recorded from. A non-breaking space arrives out of a sheet that has been
     * through a web page or a PDF and is invisible in both.
     */
    private function comparable(mixed $value): string
    {
        return strtoupper(trim(str_replace(
            //The spaces a spreadsheet produces that are not the space key - see SheetNumber
            ["\u{a0}", "\u{2007}", "\u{2009}", "\u{202f}"],
            ' ',
            (string) $value,
        )));
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

        /*
         * The template's own "nominalUnits" is deliberately not read here. What a figure is in is
         * settled per cell by SheetNumber where the cell says, and by magnitude where it does not -
         * see normalisedLength(), which is where that decision is written down and why.
         */
        $skipOrFinishCheckColumnAbsoluteIndex = $firstHeadingColumnAbsoluteIndex + $tableOption['skipOrFinishCheckRelativeOffset'];

        //Loop through CSV
        foreach ($csvArray as $index => $csvRow) {
            //If at or below the 1st data row
            if ($index >= $firstDataRowIndex) {
                //End of table rule
                $shouldFinish = $this->shouldFinish($tableOption, $csvArray, $csvRow, $index, $skipOrFinishCheckColumnAbsoluteIndex);
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

                /*
                 * A description of "0" is a description. It used to be tested for truthiness,
                 * so a row whose description column held nothing but a zero was dropped
                 * silently - and so was one holding "0.0", which is how some exports write an
                 * empty profile cell.
                 */
                $hasDescription = $description !== null && trim((string) $description) !== '';

                if (! $shouldSkip && $hasDescription) {
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

                    /*
                     * Length and width, each with whatever unit its own cell declared.
                     *
                     * The unit travels with the figure because it is the only thing that can
                     * settle a reading the magnitude rule in normalisedLength() has to guess
                     * at: a cell that says "15mm" is 15mm, whatever a bare 15 in the same
                     * column would have been taken for.
                     */
                    $length = ($lengthRequiredColumnAbsoluteIndex !== null && isset($csvRow[$lengthRequiredColumnAbsoluteIndex]))
                        ? SheetNumber::tryFrom((string) $csvRow[$lengthRequiredColumnAbsoluteIndex])
                        : null;

                    $width = ($widthRequiredColumnAbsoluteIndex !== null && isset($csvRow[$widthRequiredColumnAbsoluteIndex]))
                        ? SheetNumber::tryFrom((string) $csvRow[$widthRequiredColumnAbsoluteIndex])
                        : null;

                    //Sub qty
                    $subQty = ($subQtyColumnAbsoluteIndex !== null && isset($csvRow[$subQtyColumnAbsoluteIndex]))
                        ? $this->getSubQty($csvRow[$subQtyColumnAbsoluteIndex])
                        : null;

                    $tableData[] = [
                        'index' => $index,
                        'description' => $description,
                        'material' => $material,
                        'grade' => $grade,
                        'surface' => $surface,
                        //0.0 rather than null for a cell with no number in it, as the cast used to give
                        'length_required' => $length === null ? ($lengthRequiredColumnAbsoluteIndex === null ? null : 0.0) : $length->value,
                        'width_required' => $width === null ? ($widthRequiredColumnAbsoluteIndex === null ? null : 0.0) : $width->value,
                        //What the cell itself said its figures were in, where it said anything
                        'length_units' => $length?->units,
                        'width_units' => $width?->units,
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
         *
         * Read by SheetNumber rather than cast, because a cast stops at the first separator:
         * "1,500" cast to 1.5 and then, being neither zero nor a whole number of anything,
         * ordered one and a half of a part the sheet asked for fifteen hundred of.
         */
        $float = SheetNumber::quantity($rawSubQty);

        return $float === 0.0 ? 1.0 : $float;
    }

    public function normaliseLengthWidthRequired(?string $quantity, string $lengthWidthUnits): float
    {
        /**
         * Single purpose: the figure in a length or width cell, however it is written.
         *
         * $lengthWidthUnits is every template's "nominalUnits", and it is deliberately
         * not used here. Converting by the declared unit and then letting
         * normalisedLength() apply its "below 20 must be meters" rule would convert
         * twice: 0.015 in a meters column would become 15mm, then 15000mm. The two
         * rules cannot both be authoritative, and which one wins is a decision about
         * real steel, not a tidy-up. See normalisedLength().
         *
         * The reading itself is SheetNumber's - getTableData() asks it directly, because it
         * wants the unit the cell declared as well as the figure, and this is kept as the
         * figure on its own for anything that only needs that.
         */
        return SheetNumber::tryFrom($quantity)->value ?? 0.0;
    }

    public function saveRawMaterialQuoteData(
        array $rows,
        Project $project,
        Business $business,
        ?MaterialListFile $materialListFile = null,
    ): array {
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
                $lengthRequired = $this->normalisedLength($algo, $row['length_required'] ?? null, $row['length_units'] ?? null);
                $widthRequired = $this->normalisedWidth($algo, $row['width_required'] ?? null, $row['width_units'] ?? null);

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
                    //Which upload this line came off, so the whole file can be taken back off again
                    'material_list_file_id' => $materialListFile?->id,
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
         * Anything this upload did import stops being an outstanding item, however
         * many earlier uploads left it on the list.
         *
         * Before recording this table's own failures, not after. After, it matched them on
         * description against every row of the project - so a description that one table of this
         * import read and another could not was struck off the list by the table that worked, and
         * the row that was dropped went unreported: a GR350 CHS the catalogue does not stock
         * disappeared behind a plain CHS of the same name imported from another sheet. The two are
         * different rows asking for different steel, and one of them did not import.
         */
        $project->forgetImportedItems();

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

        return $materialList;
    }

    public function normalisedLength(?string $algo, ?float $lengthRequired, ?string $cellUnits = null): ?float
    {
        /**
         * Single purpose: convert M to MM, or keep MM as MM depending on how it looks.
         * "length" for Bundle items like bolts is more likely a QTY multiplier, so leave it as null since sub qty will capture it
         *
         * Where the cell named its own unit, that is the answer and there is nothing to decide:
         * "15mm" is 15mm and "9m" is 9000mm. SheetNumber reads it off the cell, and a cell that
         * names its unit is the only unambiguous evidence there is - the template's declared units
         * describe a column, and a column is allowed to hold both.
         *
         * Where it did not, the rule is "below 20 must be meters", applied whatever the template
         * says its units are. It is right for a sheet that mixes meters and millimeters in one
         * column, which is the case it was written for and the case ImportingTest pins down, and
         * wrong for a genuine sub-20mm part in a column already in millimeters - a bare 15 becomes
         * 15000. That one is now answerable by writing "15mm" in the cell; deciding it for an
         * unmarked cell is still a decision about real steel rather than a tidy-up, and the
         * template's "nominalUnits" is checked against the sample's own magnitudes at creation -
         * see TemplateChecks::unitFindings() - rather than being used to convert.
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
                    $normalisedLength = $this->inMillimetres($lengthRequired, $cellUnits);
                }
            } else {
                //todo check this
                $normalisedLength = $lengthRequired;
            }
        }

        return $normalisedLength;
    }

    public function normalisedWidth(?string $algo, ?float $widthRequired, ?string $cellUnits = null): ?float
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
                    $normalisedWidth = $this->inMillimetres($widthRequired, $cellUnits);
                }
            } else {
                $normalisedWidth = $widthRequired;
            }
        }

        return $normalisedWidth;
    }

    /**
     * One figure in millimetres: by the unit its cell declared where it declared one, and by
     * magnitude where it did not. See normalisedLength() for why those are the two rules.
     */
    private function inMillimetres(float $figure, ?string $cellUnits): float
    {
        return match ($cellUnits) {
            'mm' => $figure,
            'm' => $figure * 1000,
            default => $figure < 20 ? $figure * 1000 : $figure,
        };
    }

    /**
     * Whether this row is the end of the table.
     *
     * @param  array<string, mixed>  $tableOption  The whole spec, because the table also ends where
     *                                             this template's own heading row appears again
     */
    public function shouldFinish(array $tableOption, array $csvArray, array $csvRow, int $index, int $skipOrFinishCheckColumnIndex): bool
    {
        /*
         * The heading row again, which is a report that repeats its headings every page.
         *
         * Nothing stopped a table here, so the first match read on through the second heading row -
         * importing the heading itself as a material - and all the way down the sheet, while the
         * second match read the rows below it a second time. Both tables import, so a four-row
         * report with one repeated heading ordered two of its rows twice. The test screen called
         * it "found 2 times, each is read as its own table, and all of them import", which was
         * true and was not the whole of what was happening.
         *
         * Each band is now its own table and only its own: this one stops where the next begins.
         */
        if ($this->headerStartIndex($csvRow, $tableOption) !== null) {
            return true;
        }

        //Has text
        if ($text = $tableOption['isLastDataRow']) {
            return $this->isLastDataRowTextContains($text, $csvRow, $skipOrFinishCheckColumnIndex);
        }

        return $this->isLastDataRowNoDataBelow($csvArray, $index, $skipOrFinishCheckColumnIndex);
    }

    public function isLastDataRowNoDataBelow(array $csvArray, int $index, int $skipOrFinishCheckColumnIndex): bool
    {
        /**
         * The check column has run out: it is blank here, and stays blank for long enough below
         * that the table has ended rather than paused.
         *
         * This was "two consecutive blank check cells", and two was not enough. A blank spacer row
         * followed by an assembly or phase heading - a row with text in column A and nothing in the
         * check column - is two blank check cells, so a grouped report stopped at the end of its
         * first group. Every material below it was silently never read: no exception, no row in the
         * "could not be read" list, and a test screen that reported the handful it did extract as
         * "2 of 2 rows would import". Under-extraction is the one failure here with no symptom, so
         * the rule errs the other way now - a row that is not a material is reported as one, which
         * is a thing somebody can see.
         */
        if (! $this->checkCellBlank($csvArray, $index, $skipOrFinishCheckColumnIndex)) {
            return false;
        }

        /*
         * What is being looked for is the table carrying on, which is a RUN of rows - two or more
         * together. One isolated line after a gap is a footer: "End of report", a total, a note.
         * Reading that as the table resuming is how the bolt summary example ended up importing
         * "MEnd of report  mm" as a material.
         */
        $run = 0;

        for ($ahead = 1; $ahead <= self::FINISH_LOOKAHEAD_ROWS; $ahead++) {
            //Past the end of the sheet, so there is certainly nothing below
            if (! array_key_exists($index + $ahead, $csvArray)) {
                break;
            }

            if ($this->checkCellBlank($csvArray, $index + $ahead, $skipOrFinishCheckColumnIndex)) {
                $run = 0;

                continue;
            }

            $run++;

            if ($run >= self::FINISH_RESUMES_AFTER_ROWS) {
                return false;
            }
        }

        /*
         * The run rule says this is the end. Before taking that, ask the one question it cannot: is
         * there a row below that actually names a material?
         *
         * A run of two is a good description of a table carrying on and a poor one of a GROUP
         * carrying on. A Tekla material list bands its rows by section and subtotals each band, and
         * a band of one - a single 300 PFC between two subtotals - is one isolated line by that
         * rule, with the next band's rows too far down to rescue it. The table ended there: on a
         * seven-row list of a customer's, four rows below the second subtotal were never read. Not
         * refused, not reported - never read, which leaves them out of "not found" and out of
         * "could not be read" too, so the import announced success and the quote was short two
         * eleven-metre RHS.
         *
         * This is the distinction the run was standing in for. A footer is a line that names no
         * material - "Subtotal", "Page 1", "End of report", a total, a rule of dashes, all of which
         * read as nothing - and a material row names one. Asking directly costs a classification of
         * at most six cells, and only on a sheet that was about to end a table anyway.
         *
         * It is deliberately only ever a reason to CARRY ON, never to stop. Where the check column
         * holds a part of a compound description rather than a description - a bolt diameter on its
         * own - nothing there reads as a material and the run rule is still what decides, exactly
         * as before.
         */
        for ($ahead = 1; $ahead <= self::FINISH_LOOKAHEAD_ROWS; $ahead++) {
            if (! array_key_exists($index + $ahead, $csvArray)) {
                break;
            }

            if ($this->readsAsMaterial($csvArray[$index + $ahead][$skipOrFinishCheckColumnIndex] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether this cell names a material, which is what separates a row of a table from the footer
     * under it. Memoised per distinct string - see $readsAsMaterial.
     */
    private function readsAsMaterial(mixed $value): bool
    {
        $text = trim((string) $value);

        if ($text === '') {
            return false;
        }

        if (! array_key_exists($text, $this->readsAsMaterial)) {
            $this->readsAsMaterial[$text] = (new DataClassificationService)->findProductConfigFromText($text) !== null;
        }

        return $this->readsAsMaterial[$text];
    }

    private function checkCellBlank(array $csvArray, int $index, int $skipOrFinishCheckColumnIndex): bool
    {
        if (! isset($csvArray[$index][$skipOrFinishCheckColumnIndex])) {
            return true;
        }

        return trim((string) $csvArray[$index][$skipOrFinishCheckColumnIndex]) === '';
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
         * If description column is blank.
         *
         * Trimmed: a cell holding a space is blank to everybody who looks at the sheet, and it
         * used to be compared to "" exactly - so a row whose check cell held whitespace was not
         * skipped, and then dropped further down for having no product in it, with nothing said.
         */
        if (! isset($csvRow[$descriptionColumnIndex])) {
            return false;
        }

        return trim((string) $csvRow[$descriptionColumnIndex]) === '';
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
         * see Template::compoundDescriptionSpec(), which builds this out of the cells recorded
         * on the template - it is how a table with no description column says what a row is
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
