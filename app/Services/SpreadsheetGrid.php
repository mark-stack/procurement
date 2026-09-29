<?php

namespace App\Services;

use App\Imports\ExcelImport;
use Illuminate\Http\UploadedFile;
use Maatwebsite\Excel\Exceptions\NoSheetsFoundException;
use Maatwebsite\Excel\Exceptions\NoTypeDetectedException;
use Maatwebsite\Excel\Exceptions\UnreadableFileException;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Reader\Exception as ReaderException;
use Throwable;

/**
 * A sample spreadsheet, addressed the way the template form addresses it.
 *
 * Excel::toArray() hands back rows of values indexed from zero. Everything an admin types into the
 * template form - "B7", "S7" - counts rows from one. This class is the one place that difference is
 * dealt with, so the checks and the parser can ask for a cell by its reference and get its value.
 *
 * It holds a whole sheet. Only the corner of it is ever described to a model; see toPrompt().
 */
class SpreadsheetGrid
{
    /**
     * @param  array<int, array<int, mixed>>  $rows  As returned by Excel::toArray()[$sheet]
     */
    public function __construct(private readonly array $rows) {}

    /**
     * The first sheet of an uploaded spreadsheet, or null if the upload is not one at all.
     *
     * The same catch list as TemplateService::readFiles(): an unreadable upload is the user's
     * problem to fix and needs no report, anything else is ours and is logged.
     */
    public static function fromUpload(UploadedFile $file): ?self
    {
        try {
            return new self(Excel::toArray(new ExcelImport, $file)[0]);
        } catch (ReaderException|UnreadableFileException|NoTypeDetectedException|NoSheetsFoundException) {
            return null;
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    public function rowCount(): int
    {
        return count($this->rows);
    }

    /**
     * The sheet as CsvService reads it: rows and columns indexed from zero.
     *
     * Everything else here addresses cells the way the form does, which is the point of the class.
     * The importer is the exception - it was written against Excel::toArray() directly - and running
     * it rather than a second copy of it is the only way a test of a template can be believed.
     *
     * @return array<int, array<int, mixed>>
     */
    public function rows(): array
    {
        return $this->rows;
    }

    /**
     * The widest row. Excel::toArray() pads rows to the sheet's used width, but a hand-made
     * fixture need not, so this cannot assume every row is the same length.
     */
    public function columnCount(): int
    {
        $widest = 0;

        foreach ($this->rows as $row) {
            $widest = max($widest, count($row));
        }

        return $widest;
    }

    /**
     * @return array<int, mixed>
     */
    public function row(int $rowNumber): array
    {
        return $this->rows[$rowNumber - 1] ?? [];
    }

    /**
     * The trimmed value at a reference, or null when the cell is empty or outside the sheet.
     *
     * Values arrive as ints, floats, nulls and strings depending on the cell, and every caller
     * wants to read them as text, so they are cast here rather than in five places.
     */
    public function value(?CellReference $cell): ?string
    {
        if (! $cell) {
            return null;
        }

        $value = $this->rows[$cell->rowNumber - 1][$cell->columnIndex] ?? null;

        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    public function cell(?string $reference): ?string
    {
        return $this->value(CellReference::tryFrom($reference));
    }

    /**
     * Whether the sheet actually extends this far. A recorded cell beyond the sheet's extent is
     * always wrong, and reads as an empty cell otherwise.
     */
    public function holds(CellReference $cell): bool
    {
        return $cell->rowNumber <= $this->rowCount()
            && $cell->columnIndex < $this->columnCount();
    }

    public function isBlankRow(int $rowNumber): bool
    {
        foreach ($this->row($rowNumber) as $value) {
            if ($value !== null && trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * The sheet written out for a model to read, one line per row, naming the column of every
     * value it lists:
     *
     *     R6: A="Mark" B="Qty" D="Profile" I="Name" O="Finish" S="Length (mm)"
     *
     * Empty cells are left out rather than written as gaps. These sheets are mostly empty - the
     * Tekla reports use every third column - so listing only what is there is both shorter and
     * less ambiguous than a grid of pipes the model has to count across.
     *
     * The caps come from config/openai.php: they bound what leaves this server as much as they
     * bound the prompt.
     *
     * @param  array{max_rows: int, max_columns: int, max_cell_characters: int, max_characters: int}  $limits
     */
    public function toPrompt(array $limits): string
    {
        $rowLimit = min($this->rowCount(), $limits['max_rows']);
        $columnLimit = min($this->columnCount(), $limits['max_columns']);

        $lines = [sprintf(
            'Sheet extends to %d rows and %d columns (A-%s). Showing rows 1-%d, columns A-%s.',
            $this->rowCount(),
            $this->columnCount(),
            CellReference::fromIndexes(max($this->columnCount() - 1, 0), 1)->columnLetter(),
            $rowLimit,
            CellReference::fromIndexes(max($columnLimit - 1, 0), 1)->columnLetter(),
        )];

        $characters = 0;

        for ($rowNumber = 1; $rowNumber <= $rowLimit; $rowNumber++) {
            $line = $this->promptLine($rowNumber, $columnLimit, $limits['max_cell_characters']);

            /*
             * Stopping mid-sheet is better than sending a truncated last line: the model is told
             * where the description stops, so it cannot mistake a cut-off row for an empty one.
             */
            if ($characters + strlen($line) > $limits['max_characters']) {
                $lines[] = sprintf('(rows %d onwards not shown)', $rowNumber);

                break;
            }

            $characters += strlen($line);
            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    private function promptLine(int $rowNumber, int $columnLimit, int $maxCellCharacters): string
    {
        $parts = [];

        for ($columnIndex = 0; $columnIndex < $columnLimit; $columnIndex++) {
            $cell = CellReference::fromIndexes($columnIndex, $rowNumber);
            $value = $this->value($cell);

            if ($value === null) {
                continue;
            }

            if (mb_strlen($value) > $maxCellCharacters) {
                $value = mb_substr($value, 0, $maxCellCharacters).'…';
            }

            //Quotes inside a value would otherwise close the one that wraps it
            $parts[] = sprintf('%s="%s"', $cell->columnLetter(), str_replace('"', "'", $value));
        }

        return $parts === []
            ? sprintf('R%d: (blank)', $rowNumber)
            : sprintf('R%d: %s', $rowNumber, implode(' ', $parts));
    }
}
