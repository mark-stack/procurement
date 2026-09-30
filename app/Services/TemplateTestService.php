<?php

namespace App\Services;

use App\Enums\NestingEnums;
use App\Formatters\NestingFormatter;
use App\Models\Business;
use App\Models\Product;
use App\Models\Template;
use App\Models\User;
use Illuminate\Http\UploadedFile;

/**
 * Runs the importer over a sample spreadsheet using a template that has not been saved, and reports
 * what would come out of it.
 *
 * TemplateChecks can say a cell reference is well formed, on the right row, and that the cell holds
 * something that looks like a quantity. What it cannot say is whether the record extracts steel: a
 * template can pass every check, match its heading row exactly, and still import nothing at all
 * because the descriptions it pulls out match no product in the master materials list, or because
 * there is no length on the rows and length_required is NOT NULL. Those are the failures that used
 * to be discovered by a customer uploading a real file and getting an empty project back.
 *
 * So this does the whole thing rather than a model of it:
 *
 *  1. CsvService::headerStartIndex() finds the heading row, exactly as an upload does.
 *  2. CsvService::getTableData() extracts the rows, exactly as an upload does - skip rules, end-of-
 *     table rule, compound descriptions, assembly marks and all.
 *  3. DataClassificationService then answers, for every row, the question the import answers: is
 *     this a material we recognise, and is there anything in the catalogue that matches it.
 *  4. ExtractedMaterialsReview reads the extracted rows the way a person would, because a template
 *     can be reading the wrong table entirely and still get through every step above: a column of
 *     assembly marks is a column of plausible strings, and a "Total" row is a material with an
 *     unusual name until something that knows steel says otherwise.
 *
 * What comes out of all four is a named checklist rather than a heap of prose, because creating a
 * template is now gated on it - see TemplateTestChecklist for what passes, what merely warns, and
 * TemplateTestCertificate for how a pass reaches the save.
 *
 * Nothing is written. No project, no RawMaterialQuote, no piece - saveRawMaterialQuoteData() is
 * deliberately not called, and the gates it applies are re-asked here instead, in its order, so the
 * verdicts say what a real import would do.
 */
class TemplateTestService
{
    /**
     * What becomes of a row, and how to say so. Each one is a gate in
     * CsvService::saveRawMaterialQuoteData(), asked in the same order it asks them.
     */
    private const STATUSES = [
        'imports' => ['label' => 'Imports', 'tone' => 'ok'],
        'clarify' => ['label' => 'Imports, needs clarification', 'tone' => 'warning'],
        'no_length' => ['label' => 'No length', 'tone' => 'error'],
        'not_in_catalogue' => ['label' => 'Not in master materials', 'tone' => 'error'],
        'other_plan' => ['label' => 'Not on this plan', 'tone' => 'warning'],
        'unrecognised' => ['label' => 'Not recognised as a material', 'tone' => 'error'],
    ];

    /**
     * How many extracted rows are classified and listed back.
     *
     * Every row costs several catalogue queries, and a report with a thousand lines says nothing in
     * its thousandth line that it has not already said in its fiftieth. Rows beyond this are still
     * counted, and the count is what the screen leads with.
     */
    private const ROW_LIMIT = 100;

    public function __construct(
        private readonly TemplateChecks $checks = new TemplateChecks,
        private readonly DataClassificationService $classifier = new DataClassificationService,
        private readonly CsvService $csv = new CsvService,
        private readonly NestingFormatter $nesting = new NestingFormatter,
        private readonly ExtractedMaterialsReview $review = new ExtractedMaterialsReview,
        private readonly TemplateTestChecklist $checklist = new TemplateTestChecklist,
        private readonly SpreadsheetImage $image = new SpreadsheetImage,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  The template form's fields, as it posts them
     * @param  int|null  $editingTemplateId  The row being edited, which is not its own duplicate
     * @return array<string, mixed>
     */
    public function run(UploadedFile $file, Business $business, array $attributes, ?int $editingTemplateId = null): array
    {
        $grid = SpreadsheetGrid::fromUpload($file);

        if ($grid === null) {
            return $this->refused($file, 'That file could not be read as a spreadsheet, so there was nothing to extract.');
        }

        /*
         * Unsaved, on purpose: the point is to try the values in the form before any of them are
         * recorded. detectionSpec() is the same conversion from cell references to offsets that an
         * upload goes through, so what is tried here is what would be matched against.
         */
        $template = new Template($attributes);
        $template->business_id = $business->id;

        $spec = $template->detectionSpec();

        if ($spec === [] || ($spec['ExpectedHeadingLabels'] ?? []) === []) {
            return $this->refused($file, 'This template has nothing to find a table by yet. Give the heading labels, the heading cell, and at least one column cell.');
        }

        $tables = $this->extract($grid, $spec);
        $extracted = array_sum(array_map(fn (array $table) => count($table['rows']), $tables));
        $rows = $this->assessed($tables, $business);

        $sampleFindings = $this->checks->againstSample($attributes, $grid);
        $catalogueEmpty = ! Product::query()->availableForBusiness($business)->exists();

        //Only asked when there are rows to read - see ExtractedMaterialsReview::review()
        $review = $this->review->review($rows, $attributes);

        $tables = array_map(fn (array $table) => [
            'heading_row' => $table['heading_row'],
            'heading_column' => $table['heading_column'],
            'extracted' => count($table['rows']),
        ], $tables);

        $checks = $this->checklist->build([
            'file' => $file->getClientOriginalName(),
            'tables' => $tables,
            'extracted' => $extracted,
            'checked' => count($rows),
            'counts' => array_count_values(array_column($rows, 'status')),
            'catalogue_empty' => $catalogueEmpty,
            'length_column' => filled($attributes['first_length_required_cell'] ?? null),
            'sub_qty_column' => filled($attributes['first_sub_qty_cell'] ?? null),
            'sample_warnings' => array_map(
                fn (array $finding) => $finding['message'],
                TemplateChecks::warnings($sampleFindings),
            ),
            'review' => $review,
            //Templates of this business that already read this file, and whether off the same rows
            'also_read_by' => $this->alsoReadBy($grid, $business, $tables, $editingTemplateId),
        ]);

        return [
            'ok' => true,
            'file' => $file->getClientOriginalName(),
            'headline' => $this->headline($extracted, $rows),
            'tables' => $tables,
            'rows' => $rows,
            'summary' => [
                'extracted' => $extracted,
                'checked' => count($rows),
                'counts' => $this->counts($rows),
            ],
            /*
             * The named checks, and whether all the ones that stop a save got through. This is what
             * the screen leads with and what the certificate is issued against - everything below it
             * is the detail behind one of these lines.
             */
            'checks' => $checks,
            'passed' => TemplateTestChecklist::passed($checks),
            /*
             * The template's screenshot, drawn rather than pasted in - and drawn here because this is
             * the one place that knows which row the heading was found on, so the picture can band the
             * table it found instead of being a picture of the top-left corner of a file.
             */
            'screenshot' => $this->image->render($grid, $attributes, $tables[0]['heading_row'] ?? null),
            //The model's own words, for the checks above that are its answers
            'review' => [
                'used' => $review['used'],
                'model' => $review['model'],
                'verdict' => $review['answer']['verdict'] ?? null,
                'confidence' => $review['answer']['confidence'] ?? null,
                'summary' => $review['answer']['summary'] ?? null,
                'issues' => array_values(array_filter(
                    is_array($review['answer']['issues'] ?? null) ? $review['answer']['issues'] : [],
                    fn ($issue) => is_string($issue) && trim($issue) !== '',
                )),
                'error' => $review['error'],
            ],
            /*
             * The record's own checks against this file, then the three things only an extraction
             * can answer: whether the table was found, whether a column every row depends on is
             * missing, and whether there is a catalogue to match against at all.
             */
            'findings' => [
                ...$sampleFindings,
                ...$this->extractionFindings($tables, $extracted),
                ...$this->criticalFieldFindings($attributes),
                ...$this->catalogueFindings($catalogueEmpty),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function refused(UploadedFile $file, string $message): array
    {
        return [
            'ok' => false,
            'file' => $file->getClientOriginalName(),
            'message' => $message,
            /*
             * A refusal is a failed checklist of one. "passed" has to be present and false on every
             * shape this returns: it is what the screen enables Create on, and a missing key there
             * would read as a template nobody has to test.
             */
            'checks' => $this->checklist->refused($message),
            'passed' => false,
        ];
    }

    /**
     * Every table this template finds in the sheet, with the rows the importer takes out of each.
     *
     * This is CsvService::detectedTables() asked one row at a time, so that the row the heading was
     * found on can be reported: a template that matches four bands of a Tekla report reads four
     * tables, and an admin looking at 39 rows needs to know they came from four places.
     *
     * @param  array<string, mixed>  $spec
     * @return list<array{heading_row: int, heading_column: string, rows: array<int, array<string, mixed>>}>
     */
    private function extract(SpreadsheetGrid $grid, array $spec): array
    {
        $tables = [];

        for ($rowNumber = 1; $rowNumber <= $grid->rowCount(); $rowNumber++) {
            $headingColumn = $this->csv->headerStartIndex($grid->row($rowNumber), $spec);

            if ($headingColumn === null) {
                continue;
            }

            $tables[] = [
                'heading_row' => $rowNumber,
                'heading_column' => CellReference::fromIndexes($headingColumn, 1)->columnLetter(),
                'rows' => $this->csv->getTableData(
                    $grid->rows(),
                    //getTableData() counts rows from zero, the way Excel::toArray() hands them back
                    ($rowNumber - 1) + (int) $spec['OffsetFromHeaderToFirstDataRow'],
                    $headingColumn,
                    $spec,
                ),
            ];
        }

        return $tables;
    }

    /**
     * The extracted rows, each with what would become of it, up to the row limit.
     *
     * @param  list<array{heading_row: int, heading_column: string, rows: array<int, array<string, mixed>>}>  $tables
     * @return list<array<string, mixed>>
     */
    private function assessed(array $tables, Business $business): array
    {
        $user = $this->catalogueUser($business);
        $assessed = [];

        foreach ($tables as $index => $table) {
            foreach ($table['rows'] as $row) {
                if (count($assessed) >= self::ROW_LIMIT) {
                    return $assessed;
                }

                $assessed[] = $this->assess($row, $index + 1, $business, $user);
            }
        }

        return $assessed;
    }

    /**
     * One extracted row, and what the import would do with it.
     *
     * The order of the gates below is CsvService::saveRawMaterialQuoteData()'s own order, because
     * which gate a row falls at is the whole answer: "not a material we recognise" and "a material
     * with nothing in the catalogue to match it" look identical on the screen and are fixed in
     * completely different places - the first in the template, the second in master materials.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function assess(array $row, int $tableNumber, Business $business, User $user): array
    {
        $description = trim((string) $row['description']);

        $assessed = [
            'table' => $tableNumber,
            //"index" is the zero-based row getTableData() read; the form talks in sheet rows
            'sheet_row' => (int) $row['index'] + 1,
            'description' => $description,
            'material' => $this->text($row['material'] ?? null),
            'grade' => $this->text($row['grade'] ?? null),
            'surface' => $this->text($row['surface'] ?? null),
            'length_required' => $row['length_required'] ?? null,
            'width_required' => $row['width_required'] ?? null,
            'sub_qty' => $row['sub_qty'] ?? null,
            'assembly_mark' => $this->text($row['assembly_mark'] ?? null),
            'product_category' => null,
            'matches' => 0,
            'length_mm' => null,
        ];

        $config = $this->classifier->findProductConfigFromText($description);

        if (! $config) {
            return $this->verdict($assessed, 'unrecognised', sprintf(
                '"%s" reads as no product the classifier knows, so the row is dropped. Either the description column is not the column recorded here, or this really is not a material.',
                $description,
            ));
        }

        $category = (string) $config['productCategory'];
        $assessed['product_category'] = $category;

        //Which nesting algorithm the catalogue gives this category, which decides how length is read
        $algo = $this->nesting->getNestingLabelsFromProductCategory($category)[0] ?? null;

        if (! $business->supplierGroupIsCurrentPlan($config['supplierGroup']->value)) {
            return $this->verdict($assessed, 'other_plan', sprintf(
                'Read as %s, which is bought from %s - not part of what this business is sold, so the row is left out.',
                $category,
                $config['supplierGroup']->value,
            ));
        }

        if ($business->meterage_only && $algo !== NestingEnums::METERAGE->value) {
            return $this->verdict($assessed, 'other_plan', sprintf(
                'Read as %s, which is not a meterage item, and this business is on the meterage-only plan.',
                $category,
            ));
        }

        $matches = $this->classifier->findGeneralProductMatchesFromText($description, $user);
        $assessed['matches'] = count($matches['results']);

        if ($assessed['matches'] === 0) {
            return $this->verdict($assessed, 'not_in_catalogue', sprintf(
                'Read as %s, but nothing in the master materials list matches it, so the row is reported to the customer as not found.',
                $category,
            ));
        }

        $assessed['length_mm'] = $this->csv->normalisedLength($algo, $row['length_required'] ?? null);

        if ($assessed['length_mm'] === null) {
            return $this->verdict($assessed, 'no_length', 'No length came off this row, and every imported row has to have one, so it is reported as unreadable.');
        }

        if ($assessed['matches'] > 1) {
            return $this->verdict($assessed, 'clarify', sprintf(
                '%d catalogue items match this description, so it imports but has to be clarified by the customer before it can be nested.',
                $assessed['matches'],
            ));
        }

        return $this->verdict($assessed, 'imports', $this->missingFieldNote($assessed, $algo, $row));
    }

    /**
     * What a row that imports is still missing. None of these stops it - the columns are nullable,
     * and a quantity that is not there is read as one - which is exactly why they are worth saying.
     *
     * @param  array<string, mixed>  $assessed
     * @param  array<string, mixed>  $row
     */
    private function missingFieldNote(array $assessed, ?string $algo, array $row): ?string
    {
        $missing = [];

        if ($row['sub_qty'] === null) {
            $missing[] = 'no quantity, so one piece is assumed';
        }

        //Plate and sheet are nested by area, and an area with no width is a length of nothing
        if ($algo === NestingEnums::AREA->value && $this->csv->normalisedWidth($algo, $row['width_required'] ?? null) === null) {
            $missing[] = 'no width, which this item needs to be nested';
        }

        return $missing === [] ? null : ucfirst(implode('; ', $missing)).'.';
    }

    /**
     * @param  array<string, mixed>  $assessed
     * @return array<string, mixed>
     */
    private function verdict(array $assessed, string $status, ?string $note = null): array
    {
        return [
            ...$assessed,
            'status' => $status,
            'status_label' => self::STATUSES[$status]['label'],
            'tone' => self::STATUSES[$status]['tone'],
            'note' => $note,
        ];
    }

    /**
     * How many rows fell at each gate, in the order the statuses are declared, leaving out the ones
     * nothing fell at.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{status: string, label: string, tone: string, count: int}>
     */
    private function counts(array $rows): array
    {
        $tallied = array_count_values(array_column($rows, 'status'));
        $counts = [];

        foreach (self::STATUSES as $status => $described) {
            if (($tallied[$status] ?? 0) === 0) {
                continue;
            }

            $counts[] = [
                'status' => $status,
                'label' => $described['label'],
                'tone' => $described['tone'],
                'count' => $tallied[$status],
            ];
        }

        return $counts;
    }

    /**
     * The one sentence the screen leads with.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function headline(int $extracted, array $rows): string
    {
        if ($extracted === 0) {
            return 'Nothing was extracted from this spreadsheet.';
        }

        $importing = count(array_filter($rows, fn (array $row) => in_array($row['status'], ['imports', 'clarify'], true)));

        return sprintf(
            '%d of %d extracted %s would import%s.',
            $importing,
            count($rows),
            count($rows) === 1 ? 'row' : 'rows',
            $extracted > count($rows) ? sprintf(' (the first %d of %d rows were checked)', count($rows), $extracted) : '',
        );
    }

    /**
     * Which of this business's live templates already read this file, and whether off the same rows.
     *
     * A template is matched against an upload alongside every other active one, so two templates that
     * find the same heading row read the same table twice and import it twice - a customer ordering
     * double the steel, from a screen where both records look correct. It became worth asking here
     * when creating a template started switching it on: before that there was a deliberate step
     * between recording one and it meeting an upload, and this is what that step was for.
     *
     * Reading the same file off a different row is a different thing entirely and not a fault: one
     * Tekla report holds four bands and a bolt summary, and each is its own template.
     *
     * @param  list<array{heading_row: int, heading_column: string, extracted: int}>  $tables
     * @return list<array{name: string, same_rows: bool}>
     */
    private function alsoReadBy(SpreadsheetGrid $grid, Business $business, array $tables, ?int $editingTemplateId): array
    {
        $ours = array_column($tables, 'heading_row');

        $others = $business->detectableTemplates()
            ->when($editingTemplateId !== null, fn ($query) => $query->whereKeyNot($editingTemplateId))
            ->orderBy('id')
            ->get();

        $found = [];

        foreach ($others as $template) {
            $spec = $template->detectionSpec();

            if ($spec === []) {
                continue;
            }

            $rows = [];

            for ($rowNumber = 1; $rowNumber <= $grid->rowCount(); $rowNumber++) {
                if ($this->csv->headerStartIndex($grid->row($rowNumber), $spec) !== null) {
                    $rows[] = $rowNumber;
                }
            }

            if ($rows === []) {
                continue;
            }

            $found[] = [
                'name' => (string) $template->name,
                'same_rows' => array_intersect($rows, $ours) !== [],
            ];
        }

        return $found;
    }

    /**
     * Whether the table was found at all, and whether anything came out of it. Both are questions
     * only a real extraction can answer, which is why they are here rather than in TemplateChecks.
     *
     * @param  list<array{heading_row: int, heading_column: string, extracted: int}>  $tables
     * @return list<array{level: string, field: string|null, message: string}>
     */
    private function extractionFindings(array $tables, int $extracted): array
    {
        if ($tables === []) {
            return [[
                'level' => 'error',
                'field' => 'expected_heading_labels',
                'message' => 'No row of this spreadsheet carries these labels in this order, so this template finds nothing in it. The labels are compared exactly - check the units and the punctuation.',
            ]];
        }

        $findings = [[
            'level' => 'ok',
            'field' => 'heading_cell',
            'message' => count($tables) === 1
                ? sprintf(
                    'The heading row was found on row %d, starting at column %s.',
                    $tables[0]['heading_row'],
                    $tables[0]['heading_column'],
                )
                : sprintf(
                    'The heading row was found %d times (rows %s). Each one is read as its own table, and all of them import.',
                    count($tables),
                    implode(', ', array_column($tables, 'heading_row')),
                ),
        ]];

        if ($extracted === 0) {
            $findings[] = [
                'level' => 'error',
                'field' => 'first_description_cell',
                'message' => sprintf(
                    'The heading row was found on row %d, but not one row of data came out from under it. Check the first row of data is the row the cells name, and that the skip and stop rules are not swallowing the table.',
                    $tables[0]['heading_row'],
                ),
            ];
        }

        return $findings;
    }

    /**
     * The two columns whose absence decides the fate of every row rather than of one cell.
     *
     * @param  array<string, mixed>  $attributes
     * @return list<array{level: string, field: string|null, message: string}>
     */
    private function criticalFieldFindings(array $attributes): array
    {
        $findings = [];

        if (blank($attributes['first_length_required_cell'] ?? null)) {
            $findings[] = [
                'level' => 'error',
                'field' => 'first_length_required_cell',
                'message' => 'No length column is recorded. A row with no length cannot be saved, so nothing from this spreadsheet would import.',
            ];
        }

        if (blank($attributes['first_sub_qty_cell'] ?? null)) {
            $findings[] = [
                'level' => 'warning',
                'field' => 'first_sub_qty_cell',
                'message' => 'No sub qty column is recorded, so every row imports as a single piece however many the spreadsheet asks for.',
            ];
        }

        return $findings;
    }

    /**
     * An empty catalogue stops every row, whatever the template does, and it does not even stop them
     * all at the same gate: the nesting algorithm a row is judged by is read off the catalogue too,
     * so on a meterage-only plan the rows are turned away as the wrong kind of product before
     * anything has looked for a match. Which is to say the verdicts below mean nothing in this state,
     * and that has to be said before any of them is read.
     *
     * @return list<array{level: string, field: string|null, message: string}>
     */
    private function catalogueFindings(bool $catalogueEmpty): array
    {
        if (! $catalogueEmpty) {
            return [];
        }

        return [[
            'level' => 'error',
            'field' => null,
            'message' => 'There are no master materials for this business to match against, so nothing below could import whatever this template does - the verdict on each row is about the empty catalogue and not about the template. Seed the catalogue first.',
        ]];
    }

    /**
     * Whose catalogue the descriptions are matched against: the business the template belongs to,
     * which is the business whose uploads it will read.
     *
     * Not the admin running the test - classification reads products through a user, and an admin's
     * own business is not the one being recorded for. A business can also be recorded against before
     * anybody has signed up to it, hence the stand-in: Product::availableFor() wants a user, and all
     * it asks the user for is their business.
     */
    private function catalogueUser(Business $business): User
    {
        if ($user = $business->users()->orderBy('id')->first()) {
            return $user;
        }

        $standIn = new User;
        $standIn->business_id = $business->id;
        $standIn->setRelation('business', $business);

        return $standIn;
    }

    /**
     * A cell as text. Values arrive as ints, floats and nulls depending on the cell, and the screen
     * shows them as they were read rather than as PHP happens to have typed them.
     */
    private function text(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
