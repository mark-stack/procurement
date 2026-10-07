<?php

namespace App\Services;

use App\Enums\TemplateEnums;
use App\Enums\TemplateSourceEnums;
use App\Models\Business;
use App\Models\Template;
use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * Fills in the template form from a sample spreadsheet.
 *
 * Recording a template means describing a customer's table - the labels its heading row carries,
 * and which column each value is in - which is both tedious to do by eye and the kind of thing that
 * is wrong by one column without anybody noticing. This reads the file instead.
 *
 * Two sources of answers, in this order:
 *
 *  1. The templates already recorded. If one of them matches the uploaded file, the cell references
 *     are arithmetic: exact, free, and the same ones the importer will read. Nothing a model says
 *     can improve on that. It also means this file already imports, which is the single most useful
 *     thing to tell an admin who is about to record it a second time.
 *
 *  2. OpenAI. For a spreadsheet matching nothing - a new customer's report, a changed export -
 *     there is nothing to do arithmetic with, and a model reading the sheet is better than an admin
 *     squinting at it. This is the case that matters now: the form it fills in is what makes that
 *     spreadsheet importable, with no code change behind it.
 *
 * Whatever comes out is then checked against the file it came from by TemplateChecks, so the form
 * arrives with its own audit next to it rather than as a column of cell references to be taken on
 * trust. It writes nothing either way.
 */
class TemplateProposalService
{
    public function __construct(
        private readonly OpenAiService $openAi = new OpenAiService,
        private readonly TemplateChecks $checks = new TemplateChecks,
        private readonly SpreadsheetImage $image = new SpreadsheetImage,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function propose(UploadedFile $file, Business $business): array
    {
        $grid = SpreadsheetGrid::fromUpload($file);

        if ($grid === null) {
            return [
                'ok' => false,
                'file' => $file->getClientOriginalName(),
                'message' => 'That file could not be read as a spreadsheet, so there was nothing to parse.',
            ];
        }

        $detections = $this->detections($grid, $business);
        $detection = $detections[0] ?? null;

        //Asked for even when a template matched: it names the template, and it is a second opinion
        $suggestion = $this->ask($grid, $business);

        $prefill = $this->merge($detection, $suggestion['answer'], $business, $file);

        /*
         * The screenshot, drawn from the sheet that was just parsed rather than asked for as a base64
         * data URL with a link to a converter. Done after the merge because it draws the columns the
         * prefill placed - see SpreadsheetImage.
         */
        $prefill['values']['screenshot'] = $this->image->render(
            $grid,
            $prefill['values'],
            $detection['heading_row'] ?? null,
        );

        $findings = [
            ...$this->checks->againstSample($prefill['values'], $grid),
            ...$this->alreadyDetectedFindings($detections),
            ...$this->disagreementFindings($detection, $suggestion['answer']),
            ...$this->truncatedSheetFindings($grid, $suggestion['answer']),
            ...$this->unmatchedFindings($detection),
        ];

        return [
            'ok' => true,
            'file' => $file->getClientOriginalName(),
            'prefill' => $prefill['values'],
            'provenance' => $prefill['provenance'],
            'detection' => $detection === null ? null : [
                'id' => $detection['template']->id,
                'name' => $detection['template']->name,
                'source' => $detection['template']->source,
                'heading_row' => $detection['heading_row'],
                'heading_column' => CellReference::fromIndexes($detection['heading_column'], 1)->columnLetter(),
            ],
            'ai' => [
                'used' => $suggestion['answer'] !== null,
                'model' => $suggestion['answer'] !== null ? $this->openAi->model() : null,
                'confidence' => $suggestion['answer']['confidence'] ?? null,
                'notes' => $suggestion['answer']['notes'] ?? null,
                'error' => $suggestion['error'],
            ],
            'findings' => $findings,
        ];
    }

    /**
     * Every recorded template whose heading row appears in this file, in the order those headings
     * appear, with the row and column the match starts at.
     *
     * This is CsvService's own matching, asked the same question one row at a time. Scoped to the
     * templates this business's uploads are actually matched against, because "does this file
     * already import" is a question about that business and not about every row in the table.
     *
     * @return list<array{template: Template, heading_row: int, heading_column: int}>
     */
    private function detections(SpreadsheetGrid $grid, Business $business): array
    {
        $csvService = new CsvService;
        $detections = [];

        $templates = Template::query()
            ->where('active', true)
            ->detectable()
            ->where('business_id', $business->id)
            ->orderBy('id')
            ->get();

        for ($rowNumber = 1; $rowNumber <= $grid->rowCount(); $rowNumber++) {
            $row = $grid->row($rowNumber);

            foreach ($templates as $template) {
                $spec = $template->detectionSpec();
                $headingColumn = $spec === [] ? null : $csvService->headerStartIndex($row, $spec);

                if ($headingColumn === null) {
                    continue;
                }

                $detections[] = [
                    'template' => $template,
                    'heading_row' => $rowNumber,
                    'heading_column' => $headingColumn,
                ];
            }
        }

        return $detections;
    }

    /**
     * What OpenAI makes of the sheet, or null with a reason.
     *
     * Never throws: a suggestion is a convenience, and an admin who has just uploaded a file wants
     * the form filled in as far as it can be plus a sentence about what went wrong - not a 500.
     *
     * @return array{answer: array<string, mixed>|null, error: string|null}
     */
    private function ask(SpreadsheetGrid $grid, Business $business): array
    {
        if (! $this->openAi->isConfigured()) {
            return [
                'answer' => null,
                'error' => 'OPENAI_API_KEY is not set, so only the templates already recorded were used.',
            ];
        }

        try {
            $answer = $this->openAi->structuredJson(
                'import_template',
                $this->schema(),
                $this->instructions(),
                $this->input($grid, $business),
            );

            return ['answer' => $answer, 'error' => null];
        } catch (RuntimeException $exception) {
            return ['answer' => null, 'error' => $exception->getMessage()];
        }
    }

    /**
     * A matching template's answers, then the model's for anything left over.
     *
     * "provenance" travels with the values so the form can say where each field came from. An admin
     * reviewing a column of cell references needs to know which are arithmetic off a matched
     * heading row and which are a model's reading of the sheet - they do not deserve equal trust.
     *
     * @param  array{template: Template, heading_row: int, heading_column: int}|null  $detection
     * @param  array<string, mixed>|null  $answer
     * @return array{values: array<string, mixed>, provenance: array<string, string>}
     */
    private function merge(?array $detection, ?array $answer, Business $business, UploadedFile $file): array
    {
        $values = [
            'name' => null,
            'source' => null,
            'type' => TemplateEnums::CAD_BILL_OF_MATERIALS->value,
            'expected_heading_labels' => [],
            'heading_cell' => null,
            ...array_fill_keys(array_keys(Template::CELL_FIELDS), null),
            'skip_or_finish_check_cell' => null,
            'should_skip_row' => null,
            'is_last_data_row' => null,
            'compound_description_prefix' => null,
            'compound_description_suffix' => null,
            'compound_description_cells' => [],
            'assembly_mark_rule' => 'NONE',
            'assembly_mark_cell' => null,
            /*
             * Not "active", which is a decision about how a template is used rather than a reading of
             * a file. The screenshot is not read from the sheet either - it is drawn from it, by
             * propose() once these values are settled, because it draws the columns they placed.
             */
            'screenshot' => null,
            'length_width_units' => 'm',
        ];
        $provenance = [];

        if ($detection !== null) {
            $detected = $this->fromDetection($detection);

            //Only the fields it actually answered: a null here is "this table has no such column"
            $detected = array_filter($detected, fn ($value) => $value !== null && $value !== []);

            $values = [...$values, ...$detected];

            foreach (array_keys($detected) as $field) {
                $provenance[$field] = 'detection';
            }
        }

        if ($answer !== null) {
            $values = [...$values, ...$this->fromAnswer($answer, $values, $provenance, $business)];
        }

        //Something in the box beats an empty required field the admin has to invent a word for
        if (blank($values['name'])) {
            $values['name'] = $this->availableName(
                $detection['template']->name ?? pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
                $business,
            );
            $provenance['name'] = $detection === null ? 'file' : 'detection';
        }

        //Required on save, and a spreadsheet rarely says which CAD package wrote it
        $values['source'] ??= TemplateSourceEnums::PROJECT_MANAGER->value;

        return ['values' => $values, 'provenance' => $provenance];
    }

    /**
     * A matching template, rewritten as cell references into this file.
     *
     * The template stores its columns as offsets from its own heading cell; this file's heading row
     * is somewhere else. Re-anchoring is what makes the prefill describe the file in front of the
     * admin rather than the sample the template was first recorded from.
     *
     * @param  array{template: Template, heading_row: int, heading_column: int}  $detection
     * @return array<string, mixed>
     */
    private function fromDetection(array $detection): array
    {
        $template = $detection['template'];
        $spec = $template->detectionSpec();
        $anchor = CellReference::fromIndexes($detection['heading_column'], $detection['heading_row']);
        $firstDataRow = $detection['heading_row'] + (int) $spec['OffsetFromHeaderToFirstDataRow'];

        //An offset of this template's, expressed as a cell of this file
        $cell = fn (?int $offset, ?int $row = null) => $offset === null
            ? null
            : (string) CellReference::fromIndexes($detection['heading_column'] + $offset, $row ?? $firstDataRow);

        $values = [
            'source' => $template->source,
            'type' => $template->type,
            'expected_heading_labels' => $spec['ExpectedHeadingLabels'],
            'heading_cell' => (string) $anchor,
            'skip_or_finish_check_cell' => $cell($spec['skipOrFinishCheckRelativeOffset']),
            'should_skip_row' => $spec['ShouldSkipRow'],
            'is_last_data_row' => $spec['isLastDataRow'],
            'compound_description_prefix' => $spec['compoundDescription']['prefix'] ?? null,
            'compound_description_suffix' => $spec['compoundDescription']['suffix'] ?? null,
            'compound_description_cells' => array_map($cell, $spec['compoundDescription']['relativeOffsets'] ?? []),
            'assembly_mark_rule' => $spec['assemblyMarkRule'][0],
            'length_width_units' => $spec['nominalUnits'],
        ];

        //COLUMN counts its column from 1; FIXED is a cell of its own, above the table
        $values['assembly_mark_cell'] = match ($spec['assemblyMarkRule'][0]) {
            'COLUMN' => $cell($spec['assemblyMarkRule'][1] - 1),
            'FIXED' => $cell($spec['assemblyMarkRule'][1][0], $detection['heading_row'] + $spec['assemblyMarkRule'][1][1]),
            default => null,
        };

        foreach ($this->checks->expectedCells($detection) as $field => $expected) {
            $values[$field] = (string) $expected;
        }

        return $values;
    }

    /**
     * The model's reading, for every field a matching template did not already answer.
     *
     * @param  array<string, mixed>  $answer
     * @param  array<string, mixed>  $values
     * @param  array<string, string>  $provenance
     * @return array<string, mixed>
     */
    private function fromAnswer(array $answer, array $values, array &$provenance, Business $business): array
    {
        $filled = [];

        $take = function (string $field, mixed $value) use (&$filled, $provenance) {
            //Detection has already answered this one, and its answer is the arithmetic one
            if (isset($provenance[$field]) || blank($value)) {
                return;
            }

            $filled[$field] = $value;
        };

        $cell = fn (mixed $value) => is_string($value)
            ? (string) CellReference::tryFrom(strtoupper($value))
            : null;

        foreach (array_keys(Template::CELL_FIELDS) as $field) {
            $take($field, $cell($answer[$field] ?? null));
        }

        $take('heading_cell', $cell($answer['heading_cell'] ?? null));
        $take('source', in_array($answer['source'] ?? null, array_column(TemplateSourceEnums::cases(), 'value'), true) ? $answer['source'] : null);
        $take('type', in_array($answer['type'] ?? null, array_column(TemplateEnums::cases(), 'value'), true) ? $answer['type'] : null);
        $take('length_width_units', in_array($answer['length_width_units'] ?? null, ['m', 'mm'], true) ? $answer['length_width_units'] : null);
        $take('name', filled($answer['name'] ?? null) ? $this->availableName((string) $answer['name'], $business) : null);

        /*
         * The rules. Each one is checked rather than trusted, for the reason
         * TemplateLearningService::PROPOSAL_COLUMNS exists: this is a model's answer on its way to
         * a row of the templates table, and the skip and stop rules are compared against a
         * customer's spreadsheet verbatim.
         */
        $take('skip_or_finish_check_cell', $cell($answer['skip_or_finish_check_cell'] ?? null));
        $take('should_skip_row', $this->rule($answer['should_skip_row'] ?? null));
        $take('is_last_data_row', $this->rule($answer['is_last_data_row'] ?? null));
        $take('compound_description_prefix', $this->rule($answer['compound_description_prefix'] ?? null, 50));
        $take('compound_description_suffix', $this->rule($answer['compound_description_suffix'] ?? null, 50));

        $compoundCells = array_values(array_filter(array_map(
            $cell,
            is_array($answer['compound_description_cells'] ?? null) ? $answer['compound_description_cells'] : [],
        )));

        //Only where the sheet has no description column: both at once is a description read twice
        if ($values['compound_description_cells'] === [] && $compoundCells !== [] && blank($filled['first_description_cell'] ?? $values['first_description_cell'])) {
            $filled['compound_description_cells'] = array_slice($compoundCells, 0, 10);
        }

        $markRule = in_array($answer['assembly_mark_rule'] ?? null, ['NONE', 'COLUMN', 'FIXED'], true)
            ? $answer['assembly_mark_rule']
            : null;
        $markCell = $cell($answer['assembly_mark_cell'] ?? null);

        //A rule with no cell reads no mark, so the pair is taken together or not at all
        if ($markRule !== null && $markRule !== 'NONE' && $markCell !== null) {
            $take('assembly_mark_rule', $markRule);
            $take('assembly_mark_cell', $markCell);
        }

        $labels = array_values(array_filter(
            array_map(fn ($label) => is_string($label) ? trim($label) : '', $answer['expected_heading_labels'] ?? []),
            fn (string $label) => $label !== '',
        ));

        if ($values['expected_heading_labels'] === [] && $labels !== []) {
            $filled['expected_heading_labels'] = $labels;
        }

        foreach (array_keys($filled) as $field) {
            $provenance[$field] = 'ai';
        }

        return $filled;
    }

    /**
     * One of the skip, stop or affix rules as a string the record can hold, or null.
     *
     * Bounded to the column's own length because these reach the templates table from outside the
     * app, and trimmed because a rule is compared to a trimmed sheet cell - a trailing space in it
     * would be a rule that can never match anything.
     */
    private function rule(mixed $value, int $maximum = 255): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return mb_substr(trim($value), 0, $maximum);
    }

    /**
     * The suggested name, or the first numbered variant of it this business does not already use.
     * Names are unique per business, and a suggestion that fails validation on submit wastes the
     * round trip the parser just saved.
     */
    private function availableName(string $name, Business $business): string
    {
        $name = trim($name) === '' ? 'Imported template' : trim(mb_substr($name, 0, 255));
        $taken = $business->templates()->pluck('name')->map(fn ($value) => mb_strtolower((string) $value))->all();

        if (! in_array(mb_strtolower($name), $taken, true)) {
            return $name;
        }

        for ($suffix = 2; $suffix <= 50; $suffix++) {
            $candidate = $name.' '.$suffix;

            if (! in_array(mb_strtolower($candidate), $taken, true)) {
                return $candidate;
            }
        }

        return $name;
    }

    /**
     * This file already imports.
     *
     * The most important thing the screen can say, and it only became true when templates started
     * driving detection: recording a second template for a table that already matches one means
     * every row of it is read twice, once per template, and imported twice.
     *
     * @param  list<array{template: Template, heading_row: int, heading_column: int}>  $detections
     * @return list<array{level: string, field: string|null, message: string}>
     */
    private function alreadyDetectedFindings(array $detections): array
    {
        if ($detections === []) {
            return [];
        }

        $findings = [[
            'level' => 'warning',
            'field' => null,
            'message' => sprintf(
                'This file already imports under "%s". Recording it again would match the same rows twice and import them twice - edit that template instead, unless this is a different table in the same file.',
                $detections[0]['template']->name,
            ),
        ]];

        if (count($detections) > 1) {
            $others = array_map(
                fn (array $detection) => sprintf('%s (row %d)', $detection['template']->name, $detection['heading_row']),
                array_slice($detections, 1),
            );

            $findings[] = [
                'level' => 'warning',
                'field' => null,
                'message' => sprintf(
                    'It also carries %s. A file can hold several tables, and each one is its own template - the form is filled in for the first.',
                    implode(', ', $others),
                ),
            ];
        }

        return $findings;
    }

    /**
     * Where the model read a column differently to a matching template. The template's answer is
     * the one in the form, so this exists to be argued with by a human: the two disagreeing is the
     * single best sign that either the file is not quite the table it matched, or the template has
     * drifted from what the customer now exports.
     *
     * @param  array{template: Template, heading_row: int, heading_column: int}|null  $detection
     * @param  array<string, mixed>|null  $answer
     * @return list<array{level: string, field: string|null, message: string}>
     */
    private function disagreementFindings(?array $detection, ?array $answer): array
    {
        if ($detection === null || $answer === null) {
            return [];
        }

        $findings = [];

        foreach ($this->checks->expectedCells($detection) as $field => $expected) {
            $suggested = CellReference::tryFrom(is_string($answer[$field] ?? null) ? strtoupper($answer[$field]) : null);

            if ($suggested === null || (string) $suggested === (string) $expected) {
                continue;
            }

            $findings[] = [
                'level' => 'warning',
                'field' => $field,
                'message' => sprintf(
                    'OpenAI read the %s column from %s; "%s" reads it from %s, which is what the importer uses and what is filled in here.',
                    TemplateChecks::CELL_FIELDS[$field]['label'],
                    (string) $suggested,
                    $detection['template']->name,
                    (string) $expected,
                ),
            ];
        }

        return $findings;
    }

    /**
     * That the model was shown less than the whole sheet.
     *
     * Only the top-left corner goes to OpenAI - see config/openai.php, which caps the rows, the
     * columns and the characters, as much to bound what leaves this server as to bound the prompt.
     * That corner holds the heading row and the first row of data on every report we have seen, so
     * it is enough nearly always, and "nearly" is the part worth saying out loud: a sheet with a
     * title block or a cover sheet above the table can put the heading row past the cap, and every
     * cell reference in the answer is then a guess about a part of the file nobody read.
     *
     * @param  array<string, mixed>|null  $answer
     * @return list<array{level: string, field: string|null, message: string}>
     */
    private function truncatedSheetFindings(SpreadsheetGrid $grid, ?array $answer): array
    {
        if ($answer === null) {
            return [];
        }

        $limits = config('openai.grid');
        $rowsShown = min($grid->rowCount(), (int) $limits['max_rows']);
        $columnsShown = min($grid->columnCount(), (int) $limits['max_columns']);

        if ($grid->rowCount() <= $rowsShown && $grid->columnCount() <= $columnsShown) {
            return [];
        }

        return [[
            'level' => 'warning',
            'field' => null,
            'message' => sprintf(
                'This sheet is %d rows by %d columns and OpenAI was shown the first %d by %d. Anything it says about the rest is a guess - check the cells below against the file.',
                $grid->rowCount(),
                $grid->columnCount(),
                $rowsShown,
                $columnsShown,
            ),
        ]];
    }

    /**
     * A file that matches nothing - which is now the case worth being cheerful about, because
     * saving the form is the whole of what makes it importable.
     *
     * @param  array{template: Template, heading_row: int, heading_column: int}|null  $detection
     * @return list<array{level: string, field: string|null, message: string}>
     */
    private function unmatchedFindings(?array $detection): array
    {
        if ($detection !== null) {
            return [];
        }

        return [[
            'level' => 'ok',
            'field' => null,
            'message' => 'No recorded template matches this file yet. Check the cells below, mark it Active and save, and uploads of this spreadsheet will import.',
        ]];
    }

    /**
     * What the model is being asked to do. The domain matters here: it is reading a steel bill of
     * materials, and "the first cell of the description column" means nothing without knowing that
     * a description is a profile like "250PFC" and a sub quantity is how many of that piece the row
     * is for.
     */
    private function instructions(): string
    {
        return <<<'PROMPT'
        You are reading one sheet of a steel fabrication materials spreadsheet - a bill of
        materials exported from CAD software such as Tekla, Advance Steel, Inventor, SolidWorks or
        Revit, or a project quote written by hand.

        The sheet contains a table. That table has a heading row, and below it rows of data, one per
        material item. Your job is to describe that table well enough that software could find it
        again in another export of the same report.

        Two things do that:

        1. "expected_heading_labels" - the labels in the heading row, in the order they appear, left
           to right. Give the labels that identify this report, skipping blank cells between them.
           These are matched literally and in order, so copy them exactly as written, including
           units and punctuation: "Length (mm)", "Length[mm]" and "Length" are three different
           labels.
        2. "heading_cell" - the reference of the cell holding the FIRST of those labels.

        Then, for each column, the reference of the cell in the FIRST ROW OF DATA - not the heading
        cell, and not a later row:
        - description: what the item is, e.g. "250PFC", "310UB40", "M20 bolt", "Purlin C15015".
        - material: the material if there is a separate column for it, e.g. "Steel", "Aluminium".
        - grade: the steel grade if there is a separate column for it, e.g. "GRADE 300", "S355".
        - surface: the finish or treatment, e.g. "Galvanised", "Paint". Often absent.
        - length required: the length of each piece. Often headed "Length", "Length (mm)",
          "Length[mm]", "Cut length".
        - width required: the width of each piece, for plate or sheet. Usually absent.
        - sub qty: how many pieces the row is for. Often headed "Qty", "Quantity", "No.", "Count".

        Rules:
        - Report cells in A1 style, upper case, e.g. "S7". Every one of the column cells must be on
          the same row: the first row of data. The heading cell is on the heading row, above them.
        - Report null for any column the sheet does not have. Never invent a column to fill a field.
        - Skip title rows, project/phase rows, notes and blank rows above the table. The heading row
          is the row whose cells are column names, and the first data row is the first row of real
          values under it - which may be one or two rows below the heading.
        - The description column can sit to the LEFT of the heading labels you chose. That is fine;
          report the cell where it actually is.
        - "length_width_units" is what the length and width figures are written in, judged by their
          magnitude: 9000 is millimetres, 9 is metres.
        - "source" is the program that produced the sheet, chosen from the list given. Use
          PROJECT_MANAGER for anything written by a person rather than exported by CAD software.
        - "type" is CAD_BILL_OF_MATERIALS for a parts list exported from CAD, PROJECT_QUOTE for a
          priced quote or budget written by hand.
        - "name" is a short human name for this template, as an administrator would list it, e.g.
          "Tekla Assembly List" or "ConTek Bolt Summary". Two to five words.
        - "notes" is one or two sentences: which row you took as the heading, which as the first
          data row, and anything that made the sheet ambiguous. Written for an administrator who is
          about to check your answer against the spreadsheet.

        Then how the table is read, which matters as much as where the columns are:

        - "skip_or_finish_check_cell" is the cell of the first data row in the column that says
          whether a row is a material - normally the description column. The two rules below watch
          it. Null to follow the description column.
        - "should_skip_row": if rows inside the table are to be passed over and they are marked by
          one exact word in that column - "Subtotal", "Sub Total" - give that word exactly as
          written. Null otherwise. It is matched as the WHOLE cell, not as part of it.
        - "is_last_data_row": if the table ends on a row whose check cell holds one exact word -
          "Total", "Grand Total" - give that word. Null if the table simply stops. Also matched as
          the whole cell.
        - "compound_description_cells": only for a table with NO description column, where what the
          row is has to be built out of several cells. A bolt summary is the case: diameter, grade
          and length in three columns, with no cell saying "M16 8.8 65mm" anywhere. Give those
          cells, in reading order, from the first row of data - and give "compound_description_prefix"
          and "compound_description_suffix" if a letter or a unit has to be added, e.g. prefix "M"
          and suffix "mm". Leave the array empty whenever there IS a description column.
        - "assembly_mark_rule" and "assembly_mark_cell": the assembly or drawing mark the row
          belongs to, which is how a cut piece is traced back to the job. "COLUMN" if every row has
          its own mark in a column - give the mark cell on the first row of data. "FIXED" if one
          mark above the table applies to every row - give that cell. "NONE" if the sheet carries
          no mark at all. Prefer COLUMN where both are possible.
        PROMPT;
    }

    /**
     * The sheet, and the templates already recorded, in one message.
     *
     * The known templates are described by their heading labels, which is what identifies a table.
     * They are given so the model can say "this is that one again" rather than inventing a slightly
     * different set of labels for a report we already read.
     */
    private function input(SpreadsheetGrid $grid, Business $business): string
    {
        $known = Template::query()
            ->detectable()
            ->where('business_id', $business->id)
            ->orderBy('id')
            ->get()
            ->map(fn (Template $template) => sprintf(
                '- "%s": headings %s, lengths in %s.',
                $template->name,
                implode(', ', array_map(fn (string $label) => '"'.$label.'"', $template->expected_heading_labels ?? [])),
                $template->length_width_units,
            ))
            ->all();

        return implode("\n", [
            $known === []
                ? 'No templates are recorded yet, so this sheet is a new one whatever it looks like.'
                : 'TEMPLATES ALREADY RECORDED. If this sheet is one of these, use the same heading '
                    .'labels rather than a variation of them:',
            ...$known,
            '',
            'THE SHEET. One line per row; only cells that hold something are listed, each prefixed '
                .'by its column letter:',
            $grid->toPrompt(config('openai.grid')),
        ]);
    }

    /**
     * The answer's shape. Strict Structured Outputs: every property is required and nullable where
     * "no such column" is a real answer, because a field the model may simply omit is a field the
     * caller has to guess about.
     *
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        $cell = ['type' => ['string', 'null'], 'description' => 'A1-style cell reference in the first row of data, or null if the sheet has no such column.'];

        $properties = [
            'name' => ['type' => 'string'],
            'source' => ['type' => 'string', 'enum' => array_column(TemplateSourceEnums::cases(), 'value')],
            'type' => ['type' => 'string', 'enum' => array_column(TemplateEnums::cases(), 'value')],
            'expected_heading_labels' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'The heading row labels, in order, exactly as written in the sheet.',
            ],
            'heading_cell' => ['type' => ['string', 'null'], 'description' => 'A1-style reference of the cell holding the first heading label.'],
        ];

        foreach (array_keys(Template::CELL_FIELDS) as $field) {
            $properties[$field] = $cell;
        }

        /*
         * The rules, which the schema had no way of asking about at all.
         *
         * Nothing here was asked for, so every template written from a model's answer took the
         * defaults: skip the rows whose check cell is empty, stop where the check column runs out,
         * no description built out of other columns, and no assembly mark. The last two are the
         * expensive ones - a bolt summary has no description column, so it could never be described
         * automatically, and a table whose mark is in a column imported every row with no mark on
         * it at all, which is the one field traceability is drawn from.
         */
        $properties['skip_or_finish_check_cell'] = $cell;
        $properties['should_skip_row'] = ['type' => ['string', 'null'], 'description' => 'The exact text a row\'s check cell holds when the row is to be passed over, or null.'];
        $properties['is_last_data_row'] = ['type' => ['string', 'null'], 'description' => 'The exact text the check cell holds on the row that ends the table, or null.'];
        $properties['compound_description_cells'] = [
            'type' => 'array',
            'items' => ['type' => 'string'],
            'description' => 'Cells of the first data row to join into a description, in order, for a table with no description column. Empty otherwise.',
        ];
        $properties['compound_description_prefix'] = ['type' => ['string', 'null'], 'description' => 'Text to put before the joined cells, e.g. "M". Null if none.'];
        $properties['compound_description_suffix'] = ['type' => ['string', 'null'], 'description' => 'Text to put after the joined cells, e.g. "mm". Null if none.'];
        $properties['assembly_mark_rule'] = ['type' => 'string', 'enum' => ['NONE', 'COLUMN', 'FIXED']];
        //Not $cell: for FIXED this is one cell above the table rather than a cell of the first data row
        $properties['assembly_mark_cell'] = [
            'type' => ['string', 'null'],
            'description' => 'For COLUMN, the mark cell in the first row of data. For FIXED, the one cell above the table that every row takes its mark from. Null for NONE.',
        ];

        $properties['length_width_units'] = ['type' => 'string', 'enum' => ['m', 'mm']];
        $properties['confidence'] = ['type' => 'string', 'enum' => ['high', 'medium', 'low']];
        $properties['notes'] = ['type' => 'string'];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => array_keys($properties),
            'properties' => $properties,
        ];
    }
}
