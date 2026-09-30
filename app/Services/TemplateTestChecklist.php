<?php

namespace App\Services;

/**
 * The list of checks a template has to get through before it can be created, each one named.
 *
 * The findings this screen already produced are prose, one sentence per thing noticed, and prose is
 * the wrong shape for a gate: "no error was mentioned" is not the same statement as "every check
 * passed", and an admin reading a quiet panel cannot tell which of the two they are looking at. So the
 * checks are enumerated instead. Each one is asked out loud, has a name, and comes back as passed,
 * warned, failed or skipped - which is what lets the screen show a column of green ticks and the save
 * refuse a template whose ticks are not all there.
 *
 * Four statuses, and only one of them stops a save:
 *
 *  - "pass": asked and answered. This is the green tick.
 *  - "warning": something is odd and worth reading, and a real customer's spreadsheet is allowed to be
 *    odd. Some rows of a genuine bill of materials are not steel; some templates honestly have no
 *    quantity column. Never blocks.
 *  - "fail": this template would import nothing, or would import the wrong thing. Blocks.
 *  - "skipped": the check could not be asked. Only OpenAI's, and only because it was unreachable -
 *    which is not evidence against a template and so must not block one.
 *
 * The rule for the difference between a warning and a failure, everywhere below: some of the rows
 * falling at a gate is a warning, because the sheet is allowed to hold rows we do not want. All of
 * them falling at the same gate is a failure, because that is a column being read from the wrong
 * place and not a spreadsheet being untidy.
 */
class TemplateTestChecklist
{
    public const PASS = 'pass';

    public const WARNING = 'warning';

    public const FAIL = 'fail';

    public const SKIPPED = 'skipped';

    /**
     * The checks, in the order they are shown: can the file be read, is the table found, do rows come
     * out, are those rows materials, and finally what a reader makes of the whole thing.
     *
     * @param  array{
     *     file: string,
     *     tables: list<array{heading_row: int, heading_column: string, extracted: int}>,
     *     extracted: int,
     *     checked: int,
     *     counts: array<string, int>,
     *     catalogue_empty: bool,
     *     length_column: bool,
     *     sub_qty_column: bool,
     *     sample_warnings: list<string>,
     *     review: array{used: bool, model: string|null, answer: array<string, mixed>|null, error: string|null},
     *     also_read_by: list<array{name: string, same_rows: bool}>,
     * }  $facts
     * @return list<array{key: string, label: string, status: string, detail: string}>
     */
    public function build(array $facts): array
    {
        return array_values(array_filter([
            $this->fileRead($facts),
            $this->recordChecks(),
            $this->tableFound($facts),
            $this->rowsExtracted($facts),
            $this->lengthColumn($facts),
            $this->subQtyColumn($facts),
            $this->catalogue($facts),
            $this->recognised($facts),
            $this->inCatalogue($facts),
            $this->lengths($facts),
            $this->plan($facts),
            $this->imports($facts),
            $this->notAlreadyRead($facts),
            $this->agreesWithSample($facts),
            ...$this->reviewChecks($facts),
        ]));
    }

    /**
     * The one check a file that could not be read gets. Nothing else was asked, so nothing else is
     * claimed - a checklist of unasked questions reads as a list of things that are fine.
     *
     * @return list<array{key: string, label: string, status: string, detail: string}>
     */
    public function refused(string $reason): array
    {
        return [[
            'key' => 'sample_read',
            //Both refusals: a file that is not a spreadsheet, and a record with nothing to find a table by
            'label' => 'The importer could be run over the sample',
            'status' => self::FAIL,
            'detail' => $reason,
        ]];
    }

    /**
     * Whether every check that stops a save got through. This is the whole of the gate.
     *
     * @param  list<array{key: string, label: string, status: string, detail: string}>  $checks
     */
    public static function passed(array $checks): bool
    {
        foreach ($checks as $check) {
            if ($check['status'] === self::FAIL) {
                return false;
            }
        }

        return $checks !== [];
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return array{key: string, label: string, status: string, detail: string}
     */
    private function fileRead(array $facts): array
    {
        return $this->check('sample_read', 'The sample spreadsheet could be read', self::PASS, sprintf(
            '%s was parsed into a grid and the importer was run over it.',
            $facts['file'],
        ));
    }

    /**
     * Passed by the time anything here runs: the same checks refuse the test request itself, under the
     * field they are about. Listed anyway, because "the cells are cells, they name one row, and there
     * is something to read a description from" is a real check and a silent one is indistinguishable
     * from one nobody wrote.
     *
     * @return array{key: string, label: string, status: string, detail: string}
     */
    private function recordChecks(): array
    {
        return $this->check('record', 'The record itself is coherent', self::PASS,
            'Every cell is a cell reference, they all name the first row of data, and there is a description to read. A record failing any of these cannot be tested or saved.',
        );
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return array{key: string, label: string, status: string, detail: string}
     */
    private function tableFound(array $facts): array
    {
        $tables = $facts['tables'];

        if ($tables === []) {
            return $this->check('table_found', 'The heading labels find a table in the sample', self::FAIL,
                'No row of this spreadsheet carries these labels in this order. The labels are compared exactly - check the units and the punctuation.',
            );
        }

        return $this->check('table_found', 'The heading labels find a table in the sample', self::PASS,
            count($tables) === 1
                ? sprintf('Found on row %d, starting at column %s.', $tables[0]['heading_row'], $tables[0]['heading_column'])
                : sprintf(
                    'Found %d times (rows %s). Each is read as its own table, and all of them import.',
                    count($tables),
                    implode(', ', array_column($tables, 'heading_row')),
                ),
        );
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return array{key: string, label: string, status: string, detail: string}
     */
    private function rowsExtracted(array $facts): array
    {
        if ($facts['extracted'] === 0) {
            return $this->check('rows_extracted', 'Rows come out from under the heading row', self::FAIL,
                $facts['tables'] === []
                    ? 'No table was found, so there was nothing to read rows from.'
                    : 'The heading row was found but not one row of data came out from under it. Check the first row of data is the row the cells name, and that the skip and stop rules are not swallowing the table.',
            );
        }

        return $this->check('rows_extracted', 'Rows come out from under the heading row', self::PASS, sprintf(
            '%d %s extracted.',
            $facts['extracted'],
            $facts['extracted'] === 1 ? 'row was' : 'rows were',
        ));
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return array{key: string, label: string, status: string, detail: string}
     */
    private function lengthColumn(array $facts): array
    {
        if (! $facts['length_column']) {
            return $this->check('length_column', 'A length column is recorded', self::FAIL,
                'No length column is recorded. A row with no length cannot be saved, so nothing from this spreadsheet would import.',
            );
        }

        return $this->check('length_column', 'A length column is recorded', self::PASS,
            'Every imported row has to have a length, and there is a column to read one from.',
        );
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return array{key: string, label: string, status: string, detail: string}
     */
    private function subQtyColumn(array $facts): array
    {
        if (! $facts['sub_qty_column']) {
            return $this->check('sub_qty_column', 'A quantity column is recorded', self::WARNING,
                'No sub qty column is recorded, so every row imports as a single piece however many the spreadsheet asks for.',
            );
        }

        return $this->check('sub_qty_column', 'A quantity column is recorded', self::PASS,
            'How many pieces each row is for is read from the sheet rather than assumed to be one.',
        );
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return array{key: string, label: string, status: string, detail: string}
     */
    private function catalogue(array $facts): array
    {
        if ($facts['catalogue_empty']) {
            return $this->check('catalogue', 'This business has master materials to match against', self::FAIL,
                'There are no master materials for this business, so nothing could import whatever this template does - and the verdict on each row below is about the empty catalogue rather than about the template. Seed the catalogue first.',
            );
        }

        return $this->check('catalogue', 'This business has master materials to match against', self::PASS,
            'The descriptions below were matched against the catalogue this business buys from.',
        );
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return array{key: string, label: string, status: string, detail: string}
     */
    private function recognised(array $facts): array
    {
        return $this->rowGate(
            $facts,
            'unrecognised',
            'recognised_as_materials',
            'Every extracted row reads as a material',
            fn (int $count) => sprintf(
                '%d of %d extracted %s read as no product the classifier knows, so %s dropped. Either those rows are not steel, or the skip and stop rules are letting through rows that are not materials.',
                $count,
                $facts['checked'],
                $count === 1 ? 'row' : 'rows',
                $count === 1 ? 'it is' : 'they are',
            ),
            'None of the extracted rows reads as a product the classifier knows. That is the description column being read from the wrong place rather than a spreadsheet full of things we do not sell.',
            'Every row was read as a product this classifier recognises.',
        );
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return array{key: string, label: string, status: string, detail: string}
     */
    private function inCatalogue(array $facts): array
    {
        return $this->rowGate(
            $facts,
            'not_in_catalogue',
            'in_master_materials',
            'Every material matches the master materials list',
            fn (int $count) => sprintf(
                '%d of %d rows match nothing in the master materials list and would be reported to the customer as not found. That is fixed in the catalogue, not here.',
                $count,
                $facts['checked'],
            ),
            'Not one row matches anything in the master materials list. The template reads descriptions the catalogue has never heard of - check the description column before adding a thousand products.',
            'Every description found something in the catalogue to be bought as.',
        );
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return array{key: string, label: string, status: string, detail: string}
     */
    private function lengths(array $facts): array
    {
        return $this->rowGate(
            $facts,
            'no_length',
            'lengths_present',
            'Every row has a length to cut to',
            fn (int $count) => sprintf(
                '%d of %d rows have no length and would be reported as unreadable. Check those rows in the sheet, and that the length column is the column recorded here.',
                $count,
                $facts['checked'],
            ),
            'Not one row has a length. The length cell is recorded but it is reading an empty or unusable column, so nothing would import.',
            'A length came off every row, in the units recorded here.',
        );
    }

    /**
     * Off-plan rows, which are never a failure on their own: one sheet legitimately holds sections this
     * business buys and bolts it does not. When they are every row, "at least one row imports" is the
     * check that says so, and it says it about the plan rather than about the template.
     *
     * @param  array<string, mixed>  $facts
     * @return array{key: string, label: string, status: string, detail: string}|null
     */
    private function plan(array $facts): ?array
    {
        $count = $facts['counts']['other_plan'] ?? 0;

        if ($count === 0) {
            return null;
        }

        return $this->check('plan', 'Every material is something this business is sold', self::WARNING, sprintf(
            '%d of %d rows are products this business is not sold on its current plan, so they are left out of the import.',
            $count,
            $facts['checked'],
        ));
    }

    /**
     * The one question the whole screen exists to answer.
     *
     * @param  array<string, mixed>  $facts
     * @return array{key: string, label: string, status: string, detail: string}
     */
    private function imports(array $facts): array
    {
        $importing = ($facts['counts']['imports'] ?? 0) + ($facts['counts']['clarify'] ?? 0);

        if ($importing === 0) {
            return $this->check('imports', 'Materials actually import', self::FAIL,
                'Not one extracted row would be saved. A customer uploading this spreadsheet would get an empty project back.',
            );
        }

        return $this->check('imports', 'Materials actually import', self::PASS, sprintf(
            '%d of %d checked %s would be saved as material to quote and nest.',
            $importing,
            $facts['checked'],
            $importing === 1 ? 'row' : 'rows',
        ));
    }

    /**
     * That no template this business already has reads the same rows of this file.
     *
     * The only check here that fails on a template being too *good*. Every active template is matched
     * against every upload, so two that find the same heading row both read that table and both
     * import it - the customer orders twice the steel, off two records that each look right. A fail,
     * because "imports the wrong thing" is what a fail is for, and doubling an order is the most
     * expensive wrong thing this screen can wave through.
     *
     * Reading the same file off different rows is not that: one Tekla report holds four bands and a
     * bolt summary, each its own template, and saying so is worth a line.
     *
     * @param  array<string, mixed>  $facts
     * @return array{key: string, label: string, status: string, detail: string}|null
     */
    private function notAlreadyRead(array $facts): ?array
    {
        $others = $facts['also_read_by'];

        //Nothing found it, and nothing extracted means nothing to have found twice
        if ($others === [] && $facts['extracted'] === 0) {
            return null;
        }

        $sameRows = array_values(array_filter($others, fn (array $other) => $other['same_rows']));

        if ($sameRows !== []) {
            return $this->check('not_already_read', 'No template of this business already reads these rows', self::FAIL, sprintf(
                'This file already imports under %s, off the same heading row. Both would be matched against every upload, so every row of this table would be read twice and ordered twice. Edit that template instead of recording a second one.',
                $this->named($sameRows),
            ));
        }

        if ($others !== []) {
            return $this->check('not_already_read', 'No template of this business already reads these rows', self::WARNING, sprintf(
                'This file also carries %s, on other rows. A file can hold several tables and each is its own template, so this is only worth checking rather than fixing.',
                $this->named($others),
            ));
        }

        return $this->check('not_already_read', 'No template of this business already reads these rows', self::PASS,
            'Nothing else this business has recorded finds a table in this file, so these rows are imported once.',
        );
    }

    /**
     * @param  list<array{name: string, same_rows: bool}>  $others
     */
    private function named(array $others): string
    {
        return implode(', ', array_map(fn (array $other) => '"'.$other['name'].'"', $others));
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return array{key: string, label: string, status: string, detail: string}
     */
    private function agreesWithSample(array $facts): array
    {
        if ($facts['sample_warnings'] !== []) {
            return $this->check('sample_agrees', 'Nothing about the record disagrees with the sample', self::WARNING,
                implode(' ', $facts['sample_warnings']),
            );
        }

        return $this->check('sample_agrees', 'Nothing about the record disagrees with the sample', self::PASS,
            'The cells hold what the record says they hold, the columns are distinct, and the lengths are written in the units recorded.',
        );
    }

    /**
     * What a reader who knows steel makes of the extracted rows.
     *
     * The first is the gate: whether this is a materials list at all, and whether the verdict on the
     * extraction as a whole is that it is broken. The other three are its reasons, shown so that a
     * refusal can be read rather than taken on trust - and so that an admin who disagrees with the
     * model can see exactly which of its answers to argue with.
     *
     * @param  array<string, mixed>  $facts
     * @return list<array{key: string, label: string, status: string, detail: string}>
     */
    private function reviewChecks(array $facts): array
    {
        $review = $facts['review'];
        $answer = $review['answer'];

        /*
         * Not asked, so not answered. A skip is never a failure: OpenAI being unreachable says nothing
         * whatever about the template, and a gate that turns on it would make recording a template
         * impossible on a machine with no key - including every machine the tests run on.
         */
        if (! is_array($answer)) {
            return [$this->check('ai_materials_list', 'AI reads the extracted rows as a materials list', self::SKIPPED,
                (string) ($review['error'] ?? 'The extracted rows were not reviewed.'),
            )];
        }

        $verdict = $answer['verdict'] ?? 'suspect';
        $isList = ($answer['looks_like_materials_list'] ?? false) === true;
        $summary = trim((string) ($answer['summary'] ?? ''));

        $checks = [
            $this->check(
                'ai_materials_list',
                'AI reads the extracted rows as a materials list',
                $isList && $verdict !== 'invalid' ? ($verdict === 'valid' ? self::PASS : self::WARNING) : self::FAIL,
                $summary === '' ? 'No summary was given.' : $summary,
            ),
        ];

        foreach ([
            'description_column_correct' => ['ai_description_column', 'AI reads the description column as descriptions'],
            'columns_aligned' => ['ai_columns_aligned', 'AI sees no column reading from the wrong place'],
            'quantities_plausible' => ['ai_quantities', 'AI finds the quantities plausible for cut steel'],
            'lengths_plausible' => ['ai_lengths', 'AI finds the lengths plausible for cut steel'],
        ] as $field => [$key, $label]) {
            /*
             * A warning rather than a failure, even for the description column: "invalid" is where the
             * model states the extraction is broken, and one boolean disagreeing with an otherwise
             * valid verdict is a reason to look rather than a reason nobody can save anything.
             */
            $checks[] = $this->check($key, $label,
                ($answer[$field] ?? false) === true ? self::PASS : self::WARNING,
                ($answer[$field] ?? false) === true
                    ? 'No objection.'
                    : 'The model disagrees with this one. Its reasons are listed under the review below.',
            );
        }

        return $checks;
    }

    /**
     * A check about how many rows fell at one of the importer's gates: some of them is a warning, all
     * of them is a column being read from the wrong place.
     *
     * @param  array<string, mixed>  $facts
     * @param  callable(int): string  $someDetail
     * @return array{key: string, label: string, status: string, detail: string}
     */
    private function rowGate(array $facts, string $status, string $key, string $label, callable $someDetail, string $allDetail, string $noneDetail): array
    {
        $count = $facts['counts'][$status] ?? 0;

        if ($count === 0) {
            return $this->check($key, $label, self::PASS, $facts['checked'] === 0 ? 'No rows were checked.' : $noneDetail);
        }

        if ($count >= $facts['checked'] && $facts['checked'] > 0) {
            return $this->check($key, $label, self::FAIL, $allDetail);
        }

        return $this->check($key, $label, self::WARNING, $someDetail($count));
    }

    /**
     * @return array{key: string, label: string, status: string, detail: string}
     */
    private function check(string $key, string $label, string $status, string $detail): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'status' => $status,
            'detail' => $detail,
        ];
    }
}
