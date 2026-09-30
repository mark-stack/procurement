<?php

namespace App\Services;

use RuntimeException;

/**
 * Asks OpenAI whether what a template just extracted reads as a list of steel materials.
 *
 * Everything else on the template screen checks the record: is that a cell, is it on the first row of
 * data, does the description match something in the catalogue. All of it can pass on a template that
 * is reading the wrong table. A column of assembly marks is a column of plausible strings; a column
 * of weights is a column of plausible numbers; "Total" rows survive a skip rule that was written for
 * "Subtotal". None of those is a broken cell reference, and none of them is something the classifier
 * can object to - they are wrong in the way only somebody reading the extracted rows would notice.
 *
 * So the rows themselves are read, as a whole, by something that knows what a bill of materials looks
 * like. Two of the answers gate the save - whether this is a materials list at all, and the overall
 * verdict - and the rest are shown as checks to be argued with.
 *
 * It never throws and it never blocks on being unavailable: no key, a timeout or a refusal all come
 * back as "not asked, and here is why", which shows on the screen as a check that was skipped rather
 * than one that failed. Being unable to reach OpenAI is not evidence against a template.
 */
class ExtractedMaterialsReview
{
    /**
     * How many extracted rows are sent.
     *
     * Enough to see the shape of the table and to catch the row that is not a material; far fewer
     * than the hundred the screen will list, because the rows are a customer's spreadsheet and the
     * hundredth of them says nothing the twentieth did not.
     */
    private const ROW_LIMIT = 40;

    public function __construct(
        private readonly OpenAiService $openAi = new OpenAiService,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $rows  Assessed rows, as TemplateTestService built them
     * @param  array<string, mixed>  $attributes  The template form's fields
     * @return array{used: bool, model: string|null, answer: array<string, mixed>|null, error: string|null}
     */
    public function review(array $rows, array $attributes): array
    {
        if ($rows === []) {
            return $this->notAsked('Nothing was extracted, so there were no rows to read.');
        }

        if (! $this->openAi->isConfigured()) {
            return $this->notAsked('OPENAI_API_KEY is not set, so the extracted rows were not reviewed.');
        }

        try {
            $answer = $this->openAi->structuredJson(
                'extracted_materials_review',
                $this->schema(),
                $this->instructions(),
                $this->input($rows, $attributes),
            );
        } catch (RuntimeException $exception) {
            return $this->notAsked($exception->getMessage());
        }

        return [
            'used' => true,
            'model' => $this->openAi->model(),
            'answer' => $answer,
            'error' => null,
        ];
    }

    /**
     * @return array{used: bool, model: string|null, answer: null, error: string}
     */
    private function notAsked(string $reason): array
    {
        return [
            'used' => false,
            'model' => null,
            'answer' => null,
            'error' => $reason,
        ];
    }

    /**
     * What the model is being asked. The domain is the whole of it: "does this look like a materials
     * list" is unanswerable without knowing that "310UB40" is a section, that a length of 9 and a
     * length of 9000 are both ordinary, and that a row reading "Total" is the failure being looked
     * for rather than a material with an unusual name.
     */
    private function instructions(): string
    {
        return <<<'PROMPT'
        You are checking the output of an import. A steel fabrication business uploaded a spreadsheet -
        a bill of materials exported from CAD software, or a quote written by hand - and software read
        rows out of it using a description of the table that an administrator has just written. Your job
        is to say whether what came out reads as a genuine list of steel materials to be bought and cut.

        You are given one line per extracted row: the description, the other columns that were read, and
        how our own classifier read the description.

        A good extraction looks like this: every row is one material item. Descriptions are sections,
        plates, hollow sections, bolts or similar - "250PFC", "310UB40", "100x100x10EA", "CHS88.9x3.2",
        "PL10", "M20 8.8 65mm". Lengths are cutting lengths, either in millimetres (9000) or metres (9).
        Quantities are small whole numbers. Every row is a different item, or the same item at a
        different length.

        These are the failures to look for, and each is the mark of the table having been described
        wrongly rather than of a bad spreadsheet:

        - The rows are not materials at all: assembly marks, drawing numbers, phase names, people,
          prices, weights, notes, addresses, or a column of numbers with no profile anywhere.
        - "Total", "Subtotal", "Grand Total", a blank description, or a repeated heading has been read
          as a material. One such row means the stop rule or the skip rule is wrong.
        - The description column is the wrong column: the descriptions are plausible strings but they
          are marks ("A1", "B12", "C3") or names rather than profiles.
        - The columns are shifted: quantities in the length column, lengths in the quantity column, the
          same value in two columns, or a grade where a material should be.
        - The quantities or lengths are impossible for cut steel: a length of 0, a length of several
          hundred metres, a quantity of 0 or a quantity in the thousands, or lengths that are all
          identical in a way a real cutting list would not be.
        - The same item repeats identically over and over, which usually means one row is being read
          many times.

        Answer as follows:

        - "looks_like_materials_list": true only if these rows are a list of steel materials. False for
          a list of anything else, however tidy.
        - "description_column_correct": true if the description of each row says what to buy. False if
          the descriptions are marks, numbers, names, notes or blanks.
        - "columns_aligned": true if each value is in the field it belongs in.
        - "quantities_plausible", "lengths_plausible": true if those figures could be a real cutting
          list, judged as numbers and not as units - both millimetres and metres are correct here.
        - "verdict": "valid" when this would be a good import; "suspect" when it would mostly work but
          something specific is wrong; "invalid" when this is not a materials list, or when the
          description column is plainly the wrong column. Use "invalid" whenever
          "looks_like_materials_list" or "description_column_correct" is false.
        - "summary": one or two sentences for the administrator who is about to save this template.
          Say what the rows are, and what is wrong if anything is.
        - "issues": one short sentence per specific problem, naming the row it is about where you can,
          e.g. "Row 21 is a Total line and would be imported as a material." Empty when there is
          nothing wrong. Never repeat the summary here.

        Judge only the rows you are given. Our classifier's reading is there to help you spot a
        disagreement, not to be trusted: if it recognised a row you can see is not a material, the
        extraction is still wrong.
        PROMPT;
    }

    /**
     * The rows, as the importer read them, with what the template says it was reading.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $attributes
     */
    private function input(array $rows, array $attributes): string
    {
        $shown = array_slice($rows, 0, self::ROW_LIMIT);

        $lines = array_map(fn (array $row) => $this->line($row), $shown);

        return implode("\n", [
            sprintf(
                'THE TEMPLATE. Lengths and widths on this sheet are recorded as %s. Heading labels: %s.',
                $attributes['length_width_units'] ?? 'unknown',
                implode(', ', array_map(
                    fn ($label) => '"'.$label.'"',
                    is_array($attributes['expected_heading_labels'] ?? null) ? $attributes['expected_heading_labels'] : [],
                )),
            ),
            sprintf(
                'Columns it says the table has: %s.',
                $this->columnList($attributes),
            ),
            '',
            count($shown) === count($rows)
                ? sprintf('THE %d ROWS IT EXTRACTED:', count($rows))
                : sprintf('THE FIRST %d OF %d ROWS IT EXTRACTED:', count($shown), count($rows)),
            ...$lines,
        ]);
    }

    /**
     * One row, with the empty columns left out - an absent column and a column read from the wrong
     * place are both worth seeing, and writing "grade=" on every line hides both.
     *
     * @param  array<string, mixed>  $row
     */
    private function line(array $row): string
    {
        $parts = [sprintf('row %d', $row['sheet_row'] ?? 0)];

        $parts[] = sprintf('description="%s"', $row['description'] ?? '');

        foreach (['material', 'grade', 'surface', 'assembly_mark'] as $field) {
            if (filled($row[$field] ?? null)) {
                $parts[] = sprintf('%s="%s"', $field, $row[$field]);
            }
        }

        foreach (['sub_qty', 'length_required', 'width_required'] as $field) {
            if ($row[$field] !== null) {
                $parts[] = sprintf('%s=%s', $field, $row[$field]);
            }
        }

        /*
         * Our own verdict, so a disagreement is visible. "not in the catalogue" on every row is the
         * single most useful thing the model can be told: it is either a template reading the wrong
         * column or a catalogue that is short, and the rows are what tells the two apart.
         */
        $parts[] = sprintf(
            'our reading: %s',
            $row['product_category'] === null
                ? 'no product recognised'
                : sprintf('%s, %d catalogue match(es), %s', $row['product_category'], $row['matches'] ?? 0, $row['status'] ?? ''),
        );

        return implode(' | ', $parts);
    }

    /**
     * Which columns the template claims to have, in words. A model told the sheet has no grade column
     * will not report a missing grade as a fault.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function columnList(array $attributes): string
    {
        $named = [];

        foreach (TemplateChecks::CELL_FIELDS as $field => $described) {
            if (filled($attributes[$field] ?? null)) {
                $named[] = $described['label'];
            }
        }

        return $named === [] ? 'none' : implode(', ', $named);
    }

    /**
     * The answer's shape. Strict Structured Outputs, so every property is required - see
     * OpenAiService::structuredJson().
     *
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        $properties = [
            'looks_like_materials_list' => ['type' => 'boolean'],
            'description_column_correct' => ['type' => 'boolean'],
            'columns_aligned' => ['type' => 'boolean'],
            'quantities_plausible' => ['type' => 'boolean'],
            'lengths_plausible' => ['type' => 'boolean'],
            'verdict' => ['type' => 'string', 'enum' => ['valid', 'suspect', 'invalid']],
            'confidence' => ['type' => 'string', 'enum' => ['high', 'medium', 'low']],
            'summary' => ['type' => 'string'],
            'issues' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'One short sentence per specific problem, naming the row where possible. Empty when there is nothing wrong.',
            ],
        ];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => array_keys($properties),
            'properties' => $properties,
        ];
    }
}
