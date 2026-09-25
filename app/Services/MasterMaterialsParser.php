<?php

namespace App\Services;

use RuntimeException;

class MasterMaterialsParser
{
    /**
     * The spreadsheet is mapped by column position, so this header is the only thing standing
     * between an upstream column being inserted/reordered and every product silently importing
     * the wrong material, grade and dimensions. It is validated, not skipped.
     */
    public const HEADER = [
        'DESCRIPTION', 'PRODUCT', 'MATERIAL', 'GRADE', 'SURFACE', 'NESTING_ALGO', 'CERTS',
        'NOM UNITS', 'NOM L', 'ACTUAL L', 'NOM W', 'ACTUAL W', 'NOM H', 'ACTUAL H', 'WALL',
        'PACK 1', 'PACK 2', 'PACK 3', 'KGM', 'BASELINE_SUPPLIER',
    ];

    /**
     * Column position => product attribute. Everything the importer knows about the sheet's
     * shape lives here rather than being spread through numeric offsets.
     */
    private const COLUMNS = [
        0 => 'description',
        1 => 'product_category',
        2 => 'material',
        3 => 'grade',
        4 => 'surface',
        5 => 'nesting_algo',
        6 => 'certificates',
        7 => 'nominal_units',
        8 => 'nominal_length',
        9 => 'precise_length',
        10 => 'nominal_width',
        11 => 'precise_width',
        12 => 'nominal_height',
        13 => 'precise_height',
        14 => 'wall',
        15 => 'pack_size_1',
        16 => 'pack_size_2',
        17 => 'pack_size_3',
        18 => 'kg_per_m',
        19 => 'baseline_supplier',
    ];

    /**
     * A row missing any of these cannot be classified or nested, so it is rejected and
     * reported. Anything else missing is survivable - the row imports and the admin is told.
     */
    private const REQUIRED = ['product_category', 'material', 'nesting_algo'];

    private const EXPECTED = ['description', 'grade', 'surface', 'nominal_units'];

    /**
     * Stored as booleans, not as the sheet's literal "TRUE"/"FALSE" text.
     */
    private const BOOLEAN_COLUMNS = ['certificates'];

    /**
     * Stored as floats. Every other column keeps the sheet's string value, blanks included,
     * because the products table has always held '' rather than null for them and the
     * importer matches existing rows on those values.
     */
    private const FLOAT_COLUMNS = ['wall', 'kg_per_m'];

    /**
     * Single purpose: turn the master materials CSV stream into validated product rows.
     *
     * @param  resource  $handle
     */
    public function parse($handle): MasterMaterialsParseResult
    {
        $header = fgetcsv($handle, escape: '');
        $this->guardHeader($header);

        $rows = [];
        $rejected = [];
        $warnings = [];

        // Line 1 was the header, so data rows start at 2
        $line = 1;
        while (($row = fgetcsv($handle, escape: '')) !== false) {
            $line++;

            // Separator rows carry nothing at all and are expected - skip them silently
            if ($this->isBlank($row)) {
                continue;
            }

            $attributes = $this->toAttributes($row);

            $missingRequired = $this->missing($attributes, self::REQUIRED);
            if ($missingRequired !== []) {
                $rejected[] = [
                    'line' => $line,
                    'reason' => 'missing '.implode(', ', $missingRequired),
                ];

                continue;
            }

            $missingExpected = $this->missing($attributes, self::EXPECTED);
            if ($missingExpected !== []) {
                $warnings[] = [
                    'line' => $line,
                    'reason' => 'blank '.implode(', ', $missingExpected),
                ];
            }

            $rows[] = $attributes;
        }

        return new MasterMaterialsParseResult($rows, $rejected, $warnings);
    }

    /**
     * Single purpose: refuse the file outright when the sheet no longer has the shape the
     * positional mapping assumes. Importing it anyway would corrupt the whole catalogue.
     */
    private function guardHeader(mixed $header): void
    {
        if (! is_array($header)) {
            throw new RuntimeException('The master materials file is empty.');
        }

        $actual = array_map(fn ($label) => strtoupper(trim((string) $label)), $header);

        if ($actual === self::HEADER) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Unexpected master materials columns. Expected %d columns (%s) but found %d (%s).',
            count(self::HEADER),
            implode(', ', self::HEADER),
            count($actual),
            implode(', ', $actual),
        ));
    }

    /**
     * Single purpose: a row is blank only when every mapped column is blank. Testing the
     * description alone used to drop fully specified products that happened to be unlabelled.
     */
    private function isBlank(array $row): bool
    {
        foreach (array_keys(self::COLUMNS) as $index) {
            if (trim((string) ($row[$index] ?? '')) !== '') {
                return false;
            }
        }

        return true;
    }

    private function toAttributes(array $row): array
    {
        $attributes = [];

        foreach (self::COLUMNS as $index => $attribute) {
            $value = (string) ($row[$index] ?? '');

            $attributes[$attribute] = match (true) {
                in_array($attribute, self::BOOLEAN_COLUMNS, true) => $this->toBoolean($value),
                in_array($attribute, self::FLOAT_COLUMNS, true) => $value === '' ? null : (float) $value,
                default => $value,
            };
        }

        return $attributes;
    }

    private function toBoolean(string $value): ?bool
    {
        return match (strtoupper(trim($value))) {
            'TRUE', 'T', 'YES', 'Y', '1' => true,
            'FALSE', 'F', 'NO', 'N', '0' => false,
            default => null,
        };
    }

    /**
     * @return array<int, string>
     */
    private function missing(array $attributes, array $required): array
    {
        $missing = [];

        foreach ($required as $attribute) {
            if (trim((string) $attributes[$attribute]) === '') {
                $missing[] = $attribute;
            }
        }

        return $missing;
    }
}
