<?php

namespace App\Services;

use App\Models\Template;

/**
 * Everything that can be checked about a recorded template, in one place.
 *
 * A template row is what the importer matches uploads against: its heading labels find the table in
 * a sheet, and its cell references - measured against the heading cell they were read alongside -
 * say which column is which. So a wrong row is not a wrong note any more, it is a wrong import.
 *
 * Two kinds of finding come out, and the difference is deliberate:
 *
 *  - "error": the record cannot detect or cannot extract, whatever spreadsheet it meets. No heading
 *    labels, no heading cell, five cells naming three different rows, nothing to read a description
 *    from. StoreTemplateRequest refuses these, because saving one produces a template that silently
 *    imports nothing.
 *  - "warning": the record disagrees with the sample it was read from, or describes something
 *    unusual. Never refused - a customer's spreadsheet is allowed to be odd, and recording what it
 *    actually looks like is the point.
 *
 * A third level, "ok", is what was checked and passed. It is only shown next to a parsed sample,
 * where "sub qty cell B7 holds 4" is the reassurance that the form was filled in from the right
 * column.
 */
class TemplateChecks
{
    /**
     * The cell fields, each with the word to call it in a message. The offsets they imply live on
     * the model, which is where the record is turned into what the importer reads.
     */
    public const CELL_FIELDS = [
        'first_description_cell' => ['label' => 'description'],
        'first_material_cell' => ['label' => 'material'],
        'first_grade_cell' => ['label' => 'grade'],
        'first_surface_cell' => ['label' => 'surface'],
        'first_length_required_cell' => ['label' => 'length'],
        'first_width_required_cell' => ['label' => 'width'],
        'first_sub_qty_cell' => ['label' => 'sub qty'],
    ];

    /*
     * How far down a column to read when judging whether lengths look like metres or millimetres.
     * Enough rows to be representative of the table, few enough to stop at the end of a short one.
     */
    private const UNIT_SAMPLE_ROWS = 20;

    /**
     * What can be told from the record alone - no spreadsheet needed.
     *
     * @param  array<string, mixed>  $attributes  Template attributes, as the form posts them
     * @return list<array{level: string, field: string|null, message: string}>
     */
    public function record(array $attributes): array
    {
        $cells = $this->parsedCells($attributes);

        return [
            ...$this->rowFindings($cells),
            ...$this->columnFindings($cells),
            ...$this->headingFindings($attributes, $cells),
            ...$this->descriptionSourceFindings($attributes, $cells),
            ...$this->assemblyMarkFindings($attributes),
        ];
    }

    /**
     * Everything record() says, plus what the sample spreadsheet the form was filled in from
     * proves or disproves. This is where a cell can be shown to hold a quantity rather than
     * merely to be spelled like a cell reference.
     *
     * @param  array<string, mixed>  $attributes
     * @return list<array{level: string, field: string|null, message: string}>
     */
    public function againstSample(array $attributes, SpreadsheetGrid $grid): array
    {
        $cells = $this->parsedCells($attributes);

        $findings = [
            ...$this->record($attributes),
            ...$this->extentFindings($cells, $grid),
            ...$this->contentFindings($cells, $grid),
            ...$this->unitFindings($attributes, $cells, $grid),
            ...$this->sampleHeadingFindings($attributes, $grid),
        ];

        //The record's own geometry warning and the sample's say the same thing when both fire
        return $this->deduplicated($findings);
    }

    /**
     * The findings that must stop a save, as field => message, for FormRequest::after().
     *
     * @param  list<array{level: string, field: string|null, message: string}>  $findings
     * @return array<string, string>
     */
    public static function blocking(array $findings): array
    {
        $errors = [];

        foreach ($findings as $finding) {
            if ($finding['level'] !== 'error' || $finding['field'] === null) {
                continue;
            }

            //First one per field: the second message about the same field is never read
            $errors[$finding['field']] ??= $finding['message'];
        }

        return $errors;
    }

    /**
     * @param  list<array{level: string, field: string|null, message: string}>  $findings
     * @return list<array{level: string, field: string|null, message: string}>
     */
    public static function warnings(array $findings): array
    {
        return array_values(array_filter($findings, fn (array $finding) => $finding['level'] === 'warning'));
    }

    /**
     * The cell fields, parsed, keeping only the ones that hold a readable reference. An unreadable
     * one is already a validation error against the cell rule, and nothing here can say anything
     * useful about it.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, CellReference>
     */
    private function parsedCells(array $attributes): array
    {
        $cells = [];

        foreach (array_keys(self::CELL_FIELDS) as $field) {
            $value = $attributes[$field] ?? null;

            if ($cell = CellReference::tryFrom(is_string($value) ? $value : null)) {
                $cells[$field] = $cell;
            }
        }

        return $cells;
    }

    /**
     * All the cells are cells of one row: the first row of data. A record naming three different
     * rows describes no table that exists.
     *
     * @param  array<string, CellReference>  $cells
     * @return list<array{level: string, field: string|null, message: string}>
     */
    private function rowFindings(array $cells): array
    {
        if ($cells === []) {
            return [];
        }

        $findings = [];

        /*
         * The row most of the cells agree on is taken as the intended one, so the message points
         * at the odd cell out rather than at all of them.
         */
        $firstDataRow = $this->agreedRow($cells);

        foreach ($cells as $field => $cell) {
            if ($cell->rowNumber !== $firstDataRow) {
                $findings[] = [
                    'level' => 'error',
                    'field' => $field,
                    'message' => sprintf(
                        'Every cell here names the first row of data, and the others say row %d. %s is on row %d.',
                        $firstDataRow,
                        (string) $cell,
                        $cell->rowNumber,
                    ),
                ];
            }
        }

        /*
         * A table has its heading row above its data, so the first data row is never row 1. This
         * catches the reference typed a row short far more often than it catches anything exotic.
         */
        if ($firstDataRow === 1) {
            foreach (array_keys($cells) as $field) {
                $findings[] = [
                    'level' => 'error',
                    'field' => $field,
                    'message' => 'Row 1 cannot be the first data row - the heading row sits above it.',
                ];
            }
        }

        return $findings;
    }

    /**
     * @param  array<string, CellReference>  $cells
     * @return list<array{level: string, field: string|null, message: string}>
     */
    private function columnFindings(array $cells): array
    {
        $findings = [];
        $seen = [];

        foreach ($cells as $field => $cell) {
            $letter = $cell->columnLetter();

            /*
             * A warning, not an error: one column can honestly be two things - a Tekla "Profile"
             * column is both the description and the material - and the record is allowed to say
             * so. Two fields sharing a column by accident is the far more likely reading, though,
             * which is why it is said out loud.
             */
            if (isset($seen[$letter])) {
                $findings[] = [
                    'level' => 'warning',
                    'field' => $field,
                    'message' => sprintf(
                        'The %s and %s cells are both in column %s. Check that is deliberate.',
                        self::CELL_FIELDS[$seen[$letter]]['label'],
                        self::CELL_FIELDS[$field]['label'],
                        $letter,
                    ),
                ];

                continue;
            }

            $seen[$letter] = $field;
        }

        return $findings;
    }

    /**
     * The anchor and the labels, which are between them the whole of detection: the labels find the
     * table in a sheet, and the anchor is the origin every recorded cell is measured from. Without
     * either, the record matches nothing and reads nothing, so both are errors rather than notes.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, CellReference>  $cells
     * @return list<array{level: string, field: string|null, message: string}>
     */
    private function headingFindings(array $attributes, array $cells): array
    {
        $findings = [];
        $labels = $this->headingLabels($attributes);
        $anchor = CellReference::tryFrom(is_string($attributes['heading_cell'] ?? null) ? $attributes['heading_cell'] : null);

        if ($labels === []) {
            $findings[] = [
                'level' => 'error',
                'field' => 'expected_heading_labels',
                'message' => 'Without heading labels there is nothing to find this table by, so no upload would ever match it.',
            ];
        }

        /*
         * One label, or a handful of short ones, is a template that matches more than its own
         * report. The labels are compared in order but not side by side, so "Qty" alone finds a
         * heading row in almost any spreadsheet this business uploads - and every column is then
         * read at offsets measured for a different table. A warning rather than an error, because
         * a short report with one distinctive heading is a real thing; a single generic word is
         * the case being pointed at.
         */
        if ($labels !== [] && count($labels) < 2) {
            $findings[] = [
                'level' => 'warning',
                'field' => 'expected_heading_labels',
                'message' => sprintf(
                    'Only one heading label ("%s"). It finds this table in any sheet that happens to carry that word, and every column is measured from wherever it lands. Give two or three more of this report\'s headings.',
                    $labels[0],
                ),
            ];
        }

        if (! $anchor) {
            $findings[] = [
                'level' => 'error',
                'field' => 'heading_cell',
                'message' => 'The heading cell is what every column is measured from. Give the cell the first heading label sits in, e.g. A6.',
            ];

            return $findings;
        }

        if ($cells === []) {
            return $findings;
        }

        /*
         * The anchor is on the heading row and the cells are on the first row of data, so the
         * anchor is above them. Equal means the heading labels were read off the data row.
         */
        $firstDataRow = $this->agreedRow($cells);

        if ($anchor->rowNumber >= $firstDataRow) {
            $findings[] = [
                'level' => 'error',
                'field' => 'heading_cell',
                'message' => sprintf(
                    'The heading cell is on row %d and the data cells are on row %d. The heading row has to be above the data.',
                    $anchor->rowNumber,
                    $firstDataRow,
                ),
            ];
        }

        /*
         * More than a couple of rows between the heading and the data usually means the anchor is
         * pointing at a title rather than at the heading run. Not impossible, so not refused.
         */
        if ($firstDataRow - $anchor->rowNumber > 3) {
            $findings[] = [
                'level' => 'warning',
                'field' => 'heading_cell',
                'message' => sprintf(
                    'There are %d rows between the heading row and the first row of data. Check the heading cell is the heading and not a title above it.',
                    $firstDataRow - $anchor->rowNumber - 1,
                ),
            ];
        }

        return $findings;
    }

    /**
     * Something has to produce a description for each row, because a row without one is dropped -
     * see CsvService::getTableData(). Either a description column, or a compound description built
     * out of several cells, which is how the bolt summaries work.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, CellReference>  $cells
     * @return list<array{level: string, field: string|null, message: string}>
     */
    private function descriptionSourceFindings(array $attributes, array $cells): array
    {
        $compoundCells = $this->compoundCells($attributes);

        if (isset($cells['first_description_cell']) || $compoundCells !== []) {
            $findings = [];

            //Affixes alone build the same string for every row, which is a label and not a description
            if ($compoundCells === [] && (filled($attributes['compound_description_prefix'] ?? null) || filled($attributes['compound_description_suffix'] ?? null))) {
                $findings[] = [
                    'level' => 'warning',
                    'field' => 'compound_description_cells',
                    'message' => 'A compound description prefix or suffix was given with no cells to join, so it is ignored.',
                ];
            }

            return $findings;
        }

        return [[
            'level' => 'error',
            'field' => 'first_description_cell',
            'message' => 'Nothing here says what each row is. Give a description cell, or build one from other columns under Advanced - rows with no description are not imported.',
        ]];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return list<array{level: string, field: string|null, message: string}>
     */
    private function assemblyMarkFindings(array $attributes): array
    {
        $rule = $attributes['assembly_mark_rule'] ?? 'NONE';

        if ($rule === 'NONE' || CellReference::tryFrom(is_string($attributes['assembly_mark_cell'] ?? null) ? $attributes['assembly_mark_cell'] : null)) {
            return [];
        }

        return [[
            'level' => 'warning',
            'field' => 'assembly_mark_cell',
            'message' => sprintf('The assembly mark is set to %s but no cell is given, so no mark is read.', $rule),
        ]];
    }

    /**
     * @param  array<string, CellReference>  $cells
     * @return list<array{level: string, field: string|null, message: string}>
     */
    private function extentFindings(array $cells, SpreadsheetGrid $grid): array
    {
        $findings = [];

        foreach ($cells as $field => $cell) {
            if ($grid->holds($cell)) {
                continue;
            }

            $findings[] = [
                'level' => 'error',
                'field' => $field,
                'message' => sprintf(
                    '%s is outside this spreadsheet, which is %d rows by %d columns.',
                    (string) $cell,
                    $grid->rowCount(),
                    $grid->columnCount(),
                ),
            ];
        }

        //One blank-row message, not one per cell
        $rows = array_unique(array_map(fn (CellReference $cell) => $cell->rowNumber, $cells));

        foreach ($rows as $rowNumber) {
            if ($rowNumber <= $grid->rowCount() && $grid->isBlankRow($rowNumber)) {
                $findings[] = [
                    'level' => 'error',
                    'field' => array_key_first($cells),
                    'message' => sprintf('Row %d is empty in this spreadsheet, so it is not the first row of data.', $rowNumber),
                ];
            }
        }

        return $findings;
    }

    /**
     * What the cells actually hold in the sample. A description that reads as a number and a sub
     * quantity that reads as a word are the two ways a column is off by one, and both look
     * perfectly valid as cell references.
     *
     * @param  array<string, CellReference>  $cells
     * @return list<array{level: string, field: string|null, message: string}>
     */
    private function contentFindings(array $cells, SpreadsheetGrid $grid): array
    {
        $findings = [];

        foreach ($cells as $field => $cell) {
            if (! $grid->holds($cell)) {
                continue;
            }

            $value = $grid->value($cell);
            $label = self::CELL_FIELDS[$field]['label'];

            if ($value === null) {
                $findings[] = [
                    'level' => 'warning',
                    'field' => $field,
                    'message' => sprintf('%s is empty in this spreadsheet, so the %s column cannot be confirmed.', (string) $cell, $label),
                ];

                continue;
            }

            $number = $this->numeric($value);

            //Quantities, lengths and widths are numbers, and descriptions are not
            $findings[] = match (true) {
                in_array($field, ['first_sub_qty_cell', 'first_length_required_cell', 'first_width_required_cell'], true)
                    && $number === null => [
                        'level' => 'warning',
                        'field' => $field,
                        'message' => sprintf('%s holds "%s", which is not a number, so it does not look like the %s column.', (string) $cell, $value, $label),
                    ],
                //A sub qty holding no number at all was answered by the arm above
                $field === 'first_sub_qty_cell' && $number <= 0 => [
                    'level' => 'warning',
                    'field' => $field,
                    'message' => sprintf('%s holds %s. A first row of data with no quantity is usually the wrong row.', (string) $cell, $value),
                ],
                $field === 'first_description_cell' && $number !== null => [
                    'level' => 'warning',
                    'field' => $field,
                    'message' => sprintf('%s holds the number %s rather than a description. Check it is not the length or quantity column.', (string) $cell, $value),
                ],
                default => [
                    'level' => 'ok',
                    'field' => $field,
                    'message' => sprintf('%s cell %s holds "%s".', ucfirst($label), (string) $cell, $value),
                ],
            };
        }

        return $findings;
    }

    /**
     * Whether the recorded units match the magnitudes in the length column.
     *
     * Recorded units are reference only - normalisedLength() applies its own "below 20 must be
     * metres" rule whatever the record says - but a record claiming metres against a column of
     * 9000s is describing the spreadsheet wrongly, and that is worth saying where it is visible.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, CellReference>  $cells
     * @return list<array{level: string, field: string|null, message: string}>
     */
    private function unitFindings(array $attributes, array $cells, SpreadsheetGrid $grid): array
    {
        $cell = $cells['first_length_required_cell'] ?? null;
        $units = $attributes['length_width_units'] ?? null;

        if (! $cell || ! in_array($units, ['m', 'mm'], true)) {
            return [];
        }

        $lengths = [];

        for ($offset = 0; $offset < self::UNIT_SAMPLE_ROWS; $offset++) {
            $value = $grid->value(CellReference::fromIndexes($cell->columnIndex, $cell->rowNumber + $offset));

            if ($value !== null && ($number = $this->numeric($value)) !== null && $number > 0) {
                $lengths[] = $number;
            }
        }

        if ($lengths === []) {
            return [];
        }

        sort($lengths);
        $median = $lengths[intdiv(count($lengths), 2)];

        /*
         * 20 is the same threshold normalisedLength() uses to decide a figure must be metres, so
         * the warning and the importer cannot disagree about which side of the line a column is on.
         */
        $readsAs = $median >= 20 ? 'mm' : 'm';

        if ($readsAs === $units) {
            return [];
        }

        return [[
            'level' => 'warning',
            'field' => 'length_width_units',
            'message' => sprintf(
                'The length column reads like %s (a typical value is %s), but %s is recorded.',
                $readsAs,
                rtrim(rtrim(number_format($median, 2, '.', ''), '0'), '.'),
                $units,
            ),
        ]];
    }

    /**
     * Whether this record would actually find its table in the sample it was read from.
     *
     * This is the strongest check there is, because it runs the importer's own matching -
     * CsvService::headerStartIndex() - over the sample with this record's labels. If the labels are
     * not on the anchor's row, or the run starts in a different column, the record is describing a
     * table that is not in the file in front of it.
     *
     * @param  array<string, mixed>  $attributes
     * @return list<array{level: string, field: string|null, message: string}>
     */
    private function sampleHeadingFindings(array $attributes, SpreadsheetGrid $grid): array
    {
        $labels = $this->headingLabels($attributes);
        $anchor = CellReference::tryFrom(is_string($attributes['heading_cell'] ?? null) ? $attributes['heading_cell'] : null);

        if ($labels === [] || ! $anchor) {
            //headingFindings() has already said so, as an error
            return [];
        }

        $found = (new CsvService)->headerStartIndex($grid->row($anchor->rowNumber), ['ExpectedHeadingLabels' => $labels]);

        if ($found === null) {
            return [[
                'level' => 'warning',
                'field' => 'expected_heading_labels',
                'message' => sprintf(
                    'Row %d of this spreadsheet does not carry these labels in this order (%s), so the template would not match this file.',
                    $anchor->rowNumber,
                    implode(', ', $labels),
                ),
            ]];
        }

        if ($found !== $anchor->columnIndex) {
            return [[
                'level' => 'warning',
                'field' => 'heading_cell',
                'message' => sprintf(
                    'The heading labels start at column %s on row %d, not column %s. Every column is measured from the heading cell, so this shifts them all.',
                    CellReference::fromIndexes($found, 1)->columnLetter(),
                    $anchor->rowNumber,
                    $anchor->columnLetter(),
                ),
            ]];
        }

        return [[
            'level' => 'ok',
            'field' => 'heading_cell',
            'message' => sprintf(
                'The heading labels are on row %d starting at column %s, exactly where this template says.',
                $anchor->rowNumber,
                $anchor->columnLetter(),
            ),
        ]];
    }

    /**
     * The cells a template reads in a file whose heading row has been found, worked out from where
     * that heading row is. This is what detection itself does with the same two numbers, so it is
     * the answer the form should hold.
     *
     * @param  array{template: Template, heading_row: int, heading_column: int}  $detection
     * @return array<string, CellReference>
     */
    public function expectedCells(array $detection): array
    {
        $spec = $detection['template']->detectionSpec();

        if ($spec === []) {
            return [];
        }

        //The heading row plus the template's own distance to its first row of data
        $firstDataRow = $detection['heading_row'] + (int) $spec['OffsetFromHeaderToFirstDataRow'];

        $expected = [];

        foreach (Template::CELL_FIELDS as $field => $offsetKey) {
            $offset = $spec[$offsetKey] ?? null;

            if ($offset === null) {
                continue;
            }

            $expected[$field] = CellReference::fromIndexes(
                $detection['heading_column'] + (int) $offset,
                $firstDataRow,
            );
        }

        return $expected;
    }

    /**
     * The row most of the cells name, which is the first row of data.
     *
     * @param  array<string, CellReference>  $cells
     */
    private function agreedRow(array $cells): int
    {
        $counts = array_count_values(array_map(fn (CellReference $cell) => $cell->rowNumber, $cells));
        arsort($counts);

        return (int) array_key_first($counts);
    }

    /**
     * Heading labels, however they arrived: an array from the form, a JSON string from a raw
     * database row. Blank entries are dropped - an empty box in the repeater is not a label.
     *
     * @param  array<string, mixed>  $attributes
     * @return list<string>
     */
    private function headingLabels(array $attributes): array
    {
        $labels = $attributes['expected_heading_labels'] ?? [];

        if (is_string($labels)) {
            $labels = json_decode($labels, true) ?: [];
        }

        if (! is_array($labels)) {
            return [];
        }

        return array_values(array_filter(
            array_map(fn ($label) => is_string($label) ? trim($label) : '', $labels),
            fn (string $label) => $label !== '',
        ));
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return list<string>
     */
    private function compoundCells(array $attributes): array
    {
        $cells = $attributes['compound_description_cells'] ?? [];

        if (is_string($cells)) {
            $cells = json_decode($cells, true) ?: [];
        }

        if (! is_array($cells)) {
            return [];
        }

        return array_values(array_filter(
            array_map(fn ($cell) => is_string($cell) ? trim($cell) : '', $cells),
            fn (string $cell) => $cell !== '',
        ));
    }

    /**
     * A spreadsheet number, or null. Thousands separators and a trailing unit are both common in
     * an exported report, and neither makes the cell any less of a quantity.
     */
    private function numeric(string $value): ?float
    {
        $cleaned = trim(str_replace([',', ' ', "\u{a0}"], '', $value));

        //"9000mm", "12.5 m"
        $cleaned = (string) preg_replace('/(mm|m|kg|m2)$/i', '', $cleaned);

        return is_numeric($cleaned) ? (float) $cleaned : null;
    }

    /**
     * @param  list<array{level: string, field: string|null, message: string}>  $findings
     * @return list<array{level: string, field: string|null, message: string}>
     */
    private function deduplicated(array $findings): array
    {
        $seen = [];
        $unique = [];

        foreach ($findings as $finding) {
            $key = $finding['level'].'|'.$finding['field'].'|'.$finding['message'];

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $finding;
        }

        return $unique;
    }
}
