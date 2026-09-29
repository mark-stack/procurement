<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use Stringable;

/**
 * An A1-style cell reference, parsed once.
 *
 * Three places needed to know which column "S7" is - the checks that read a recorded template, the
 * grid that reads a sample spreadsheet, and the parser that turns a config entry's relative offsets
 * back into cell references - and each was a candidate for its own slightly different regex and its
 * own off-by-one on the column letters.
 *
 * Indexes are zero based here, matching the arrays Excel::toArray() returns. PhpSpreadsheet's
 * Coordinate counts columns from 1, and that difference is converted in exactly these two methods.
 */
final class CellReference implements Stringable
{
    /*
     * Up to 3 column letters and a row of at least 1: spreadsheet rows start at 1, so "B0" is not
     * a cell. Kept in step with StoreTemplateRequest's own rule, which is stricter in one way - it
     * accepts upper case only, because the column is stored upper cased.
     */
    private const PATTERN = '/^([A-Za-z]{1,3})([1-9][0-9]{0,6})$/';

    private function __construct(
        public readonly int $columnIndex,
        public readonly int $rowNumber,
    ) {}

    /**
     * The reference, or null if the string is not one. Null rather than an exception because every
     * caller is reading input it does not control - a recorded row, a form field, a model's answer.
     */
    public static function tryFrom(?string $reference): ?self
    {
        if (! is_string($reference) || ! preg_match(self::PATTERN, trim($reference), $matches)) {
            return null;
        }

        return new self(
            Coordinate::columnIndexFromString($matches[1]) - 1,
            (int) $matches[2],
        );
    }

    public static function fromIndexes(int $columnIndex, int $rowNumber): self
    {
        return new self(max($columnIndex, 0), max($rowNumber, 1));
    }

    public function columnLetter(): string
    {
        return Coordinate::stringFromColumnIndex($this->columnIndex + 1);
    }

    public function __toString(): string
    {
        return $this->columnLetter().$this->rowNumber;
    }
}
