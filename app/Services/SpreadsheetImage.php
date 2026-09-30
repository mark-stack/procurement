<?php

namespace App\Services;

use GdImage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Draws a picture of a sample spreadsheet, so nobody has to paste one in.
 *
 * The template screen used to ask an admin for a screenshot as a base64 data URL, 800x500, with a
 * link to somebody's CodePen for converting one. That is a chore attached to every template: take a
 * screenshot, find the converter, paste a quarter of a megabyte of text into a single-line input. It
 * is also the field most likely to be skipped or filled with whatever was on the clipboard.
 *
 * Nothing about it needed a human. By the time the screen has a screenshot to ask for it has already
 * parsed the spreadsheet into a SpreadsheetGrid, which is the same thing a screenshot is a picture
 * of - so this draws that grid instead: the column letters across the top, the row numbers down the
 * side, and the cells as they were read.
 *
 * It draws two things a real screenshot could not:
 *
 *  - the heading row and the first row of data are banded, so the picture says where the table was
 *    found and not merely what the file looks like;
 *  - each column the record names is tagged with what it is recorded as, so the thumbnail is a
 *    picture of this template reading this spreadsheet rather than a picture of a spreadsheet.
 *
 * It is a thumbnail for recognising a template by, not the authority on anything: cell text is
 * truncated to the column width, and the form's own fields are where the labels are read from.
 */
class SpreadsheetImage
{
    /*
     * How much of the sheet is drawn. Deep enough to show the rows under a heading, and bounded
     * because this is a thumbnail.
     */
    private const MAX_COLUMNS = 14;

    /**
     * How far the picture will stretch to reach a recorded column.
     *
     * The Tekla reports use every third column - their length column is S - so a fixed fourteen
     * columns draws a picture with the single most important column just off the right edge. Any
     * column the record names is worth seeing, so the width follows the record up to this ceiling.
     */
    private const COLUMN_CEILING = 26;

    private const MAX_ROWS = 26;

    /**
     * How short the picture is allowed to get when the rows run out.
     *
     * Trailing blank rows are a picture of nothing, so they are trimmed - but a table of two rows
     * trimmed to two rows is a sliver, and a sliver in a 128x80 thumbnail is not recognisable as
     * anything.
     */
    private const MIN_ROWS = 10;

    /**
     * How many rows above the heading row to start at, when there is one. A table found on row 28 is
     * a picture of rows 26 onwards; the twenty-five rows of title block above it are not the table.
     */
    private const ROWS_OF_CONTEXT = 2;

    private const COLUMN_WIDTH = 90;

    private const ROW_NUMBER_WIDTH = 46;

    private const ROW_HEIGHT = 17;

    private const LETTER_STRIP_HEIGHT = 18;

    private const ROLE_STRIP_HEIGHT = 16;

    /*
     * GD's built-in font 3, which is 7x13 pixels. A bundled TrueType font would look better and is
     * one more file to ship and keep; at this size the built-in one is legible and always present.
     */
    private const FONT = 3;

    private const CHARACTER_WIDTH = 7;

    private const CHARACTER_HEIGHT = 13;

    private const PADDING = 4;

    /**
     * The sheet as a PNG data URL, in the same shape the screenshot column has always held, or null
     * if there is nothing to draw or no GD to draw it with.
     *
     * Never throws. A missing screenshot costs a thumbnail on a list; it must not cost an admin the
     * ability to record a template, which is why the caller stores null rather than failing.
     *
     * @param  array<string, mixed>  $attributes  The template form's fields, for the column tags
     * @param  int|null  $headingRow  The row the heading labels were found on, when a test found one
     */
    public function render(SpreadsheetGrid $grid, array $attributes = [], ?int $headingRow = null): ?string
    {
        if (! function_exists('imagecreatetruecolor') || $grid->rowCount() === 0 || $grid->columnCount() === 0) {
            return null;
        }

        try {
            return $this->draw($grid, $attributes, $headingRow);
        }
        /*
         * Same posture as SpreadsheetGrid::fromUpload(): whatever went wrong here is ours, so it is
         * logged rather than swallowed - and it still costs a thumbnail rather than an admin's
         * ability to record a template.
         */
        catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function draw(SpreadsheetGrid $grid, array $attributes, ?int $headingRow): ?string
    {
        $roles = $this->roles($attributes);

        $columns = $this->columns($grid, $roles);
        $firstRow = $this->firstRow($grid, $headingRow);
        $rows = $this->rows($grid, $firstRow);

        $width = self::ROW_NUMBER_WIDTH + ($columns * self::COLUMN_WIDTH);
        $height = self::LETTER_STRIP_HEIGHT + self::ROLE_STRIP_HEIGHT + ($rows * self::ROW_HEIGHT);

        $image = imagecreatetruecolor($width, $height);

        $palette = $this->palette($image);

        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, $palette['paper']);

        //The row the cells describe, which is the one banded as the first row of data
        $firstDataRow = $this->firstDataRow($attributes);

        $this->drawHeader($image, $palette, $columns, $roles);
        $this->drawRows($image, $palette, $grid, $columns, $firstRow, $rows, $headingRow, $firstDataRow);
        $this->drawGrid($image, $palette, $columns, $rows, $width, $height);

        return $this->encoded($image);
    }

    /**
     * How wide to draw: the usual width, stretched to reach the rightmost column the record names,
     * and never wider than the sheet or the ceiling.
     *
     * @param  array<int, string>  $roles
     */
    private function columns(SpreadsheetGrid $grid, array $roles): int
    {
        $rightmostRecorded = $roles === [] ? 0 : max(array_keys($roles)) + 1;

        return min(
            $grid->columnCount(),
            max(self::MAX_COLUMNS, $rightmostRecorded),
            self::COLUMN_CEILING,
        );
    }

    /**
     * Where the picture starts: just above the heading row when one was found, and the top of the
     * sheet otherwise.
     */
    private function firstRow(SpreadsheetGrid $grid, ?int $headingRow): int
    {
        if ($headingRow === null) {
            return 1;
        }

        //Never past the end: a heading row on the last row of a short sheet still has to be in shot
        return max(1, min($headingRow - self::ROWS_OF_CONTEXT, max(1, $grid->rowCount() - self::MAX_ROWS + 1)));
    }

    /**
     * How deep to draw: a window of the sheet, with any blank rows it ends on trimmed off.
     *
     * A quote with its table at row 25 and nothing after row 34 was drawing fourteen empty rows,
     * which is most of the thumbnail spent on the part of the sheet with nothing in it.
     */
    private function rows(SpreadsheetGrid $grid, int $firstRow): int
    {
        $rows = min($grid->rowCount() - $firstRow + 1, self::MAX_ROWS);

        while ($rows > self::MIN_ROWS && $grid->isBlankRow($firstRow + $rows - 1)) {
            $rows--;
        }

        return $rows;
    }

    /**
     * Which row the recorded cells name, so it can be banded as the first row of data. Read off the
     * description cell, falling back to any cell that is filled in - they all name the same row, and
     * a record where they do not is refused before anything gets here.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function firstDataRow(array $attributes): ?int
    {
        foreach (array_keys(TemplateChecks::CELL_FIELDS) as $field) {
            $cell = CellReference::tryFrom(is_string($attributes[$field] ?? null) ? $attributes[$field] : null);

            if ($cell) {
                return $cell->rowNumber;
            }
        }

        return null;
    }

    /**
     * What each column is recorded as, by column index: "description", "length", "sub qty". Read from
     * the same place the messages are, so the picture and the checks call a column the same thing.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<int, string>
     */
    private function roles(array $attributes): array
    {
        $roles = [];

        foreach (TemplateChecks::CELL_FIELDS as $field => $described) {
            $cell = CellReference::tryFrom(is_string($attributes[$field] ?? null) ? $attributes[$field] : null);

            if (! $cell) {
                continue;
            }

            /*
             * One column can honestly be two things - a Tekla "Profile" column is both the
             * description and the material - and the tag says so rather than quietly dropping one.
             */
            $roles[$cell->columnIndex] = isset($roles[$cell->columnIndex])
                ? $roles[$cell->columnIndex].'/'.$described['label']
                : $described['label'];
        }

        return $roles;
    }

    /**
     * @return array<string, int>
     */
    private function palette(GdImage $image): array
    {
        return [
            'paper' => (int) imagecolorallocate($image, 255, 255, 255),
            'line' => (int) imagecolorallocate($image, 229, 231, 235),
            'strip' => (int) imagecolorallocate($image, 243, 244, 246),
            'strip_text' => (int) imagecolorallocate($image, 107, 114, 128),
            'role_text' => (int) imagecolorallocate($image, 67, 56, 202),
            'heading_band' => (int) imagecolorallocate($image, 219, 234, 254),
            'data_band' => (int) imagecolorallocate($image, 209, 250, 229),
            'text' => (int) imagecolorallocate($image, 17, 24, 39),
        ];
    }

    /**
     * The two strips above the sheet: the column letters, and what each column is recorded as.
     *
     * @param  array<string, int>  $palette
     * @param  array<int, string>  $roles
     */
    private function drawHeader(GdImage $image, array $palette, int $columns, array $roles): void
    {
        $stripHeight = self::LETTER_STRIP_HEIGHT + self::ROLE_STRIP_HEIGHT;

        imagefilledrectangle(
            $image,
            0,
            0,
            self::ROW_NUMBER_WIDTH + ($columns * self::COLUMN_WIDTH) - 1,
            $stripHeight - 1,
            $palette['strip'],
        );

        for ($column = 0; $column < $columns; $column++) {
            $left = self::ROW_NUMBER_WIDTH + ($column * self::COLUMN_WIDTH);
            $letter = CellReference::fromIndexes($column, 1)->columnLetter();

            $this->centred($image, $letter, $left, self::COLUMN_WIDTH, 3, $palette['strip_text']);

            if (isset($roles[$column])) {
                $this->centred(
                    $image,
                    $this->fitted($roles[$column]),
                    $left,
                    self::COLUMN_WIDTH,
                    self::LETTER_STRIP_HEIGHT + 2,
                    $palette['role_text'],
                );
            }
        }
    }

    /**
     * The cells, row by row, with the heading row and the first row of data banded.
     *
     * @param  array<string, int>  $palette
     */
    private function drawRows(
        GdImage $image,
        array $palette,
        SpreadsheetGrid $grid,
        int $columns,
        int $firstRow,
        int $rows,
        ?int $headingRow,
        ?int $firstDataRow,
    ): void {
        $stripHeight = self::LETTER_STRIP_HEIGHT + self::ROLE_STRIP_HEIGHT;
        $right = self::ROW_NUMBER_WIDTH + ($columns * self::COLUMN_WIDTH) - 1;

        for ($index = 0; $index < $rows; $index++) {
            $rowNumber = $firstRow + $index;
            $top = $stripHeight + ($index * self::ROW_HEIGHT);
            $textTop = $top + (int) ((self::ROW_HEIGHT - self::CHARACTER_HEIGHT) / 2);

            $band = match (true) {
                $rowNumber === $headingRow => $palette['heading_band'],
                $rowNumber === $firstDataRow => $palette['data_band'],
                default => null,
            };

            if ($band !== null) {
                imagefilledrectangle($image, 0, $top, $right, $top + self::ROW_HEIGHT - 1, $band);
            }

            //The row number in its gutter, the way a spreadsheet writes it
            $this->centred($image, (string) $rowNumber, 0, self::ROW_NUMBER_WIDTH, $textTop, $palette['strip_text']);

            for ($column = 0; $column < $columns; $column++) {
                $value = $grid->value(CellReference::fromIndexes($column, $rowNumber));

                if ($value === null) {
                    continue;
                }

                imagestring(
                    $image,
                    self::FONT,
                    self::ROW_NUMBER_WIDTH + ($column * self::COLUMN_WIDTH) + self::PADDING,
                    $textTop,
                    $this->fitted($value),
                    $palette['text'],
                );
            }
        }
    }

    /**
     * The lines. Drawn last so a band never covers one.
     *
     * @param  array<string, int>  $palette
     */
    private function drawGrid(GdImage $image, array $palette, int $columns, int $rows, int $width, int $height): void
    {
        $stripHeight = self::LETTER_STRIP_HEIGHT + self::ROLE_STRIP_HEIGHT;

        for ($column = 0; $column <= $columns; $column++) {
            $x = self::ROW_NUMBER_WIDTH + ($column * self::COLUMN_WIDTH);
            $x = min($x, $width - 1);

            imageline($image, $x, 0, $x, $height - 1, $palette['line']);
        }

        //The gutter's own edge, and the line under the two header strips
        imageline($image, 0, $stripHeight, $width - 1, $stripHeight, $palette['line']);
        imageline($image, 0, self::LETTER_STRIP_HEIGHT, $width - 1, self::LETTER_STRIP_HEIGHT, $palette['line']);

        for ($row = 0; $row <= $rows; $row++) {
            $y = min($stripHeight + ($row * self::ROW_HEIGHT), $height - 1);

            imageline($image, 0, $y, $width - 1, $y, $palette['line']);
        }
    }

    /**
     * One string centred in a column of this width, or padded off its left edge when it fills it.
     */
    private function centred(GdImage $image, string $text, int $left, int $columnWidth, int $y, int $colour): void
    {
        $text = $this->fitted($text, $columnWidth);
        $x = $left + max(self::PADDING, (int) (($columnWidth - (strlen($text) * self::CHARACTER_WIDTH)) / 2));

        imagestring($image, self::FONT, $x, $y, $text, $colour);
    }

    /**
     * A cell's text, as GD's built-in font can draw it and as wide as a column allows.
     *
     * Transliterated first: the built-in fonts are byte-per-glyph, so a UTF-8 "Ø" or "×" in a
     * customer's heading draws as two pieces of line noise rather than as a character. ".." marks a
     * truncation, because "Length (m" and "Length (mm)" are different labels and a picture that
     * hides the difference is worse than one that admits it.
     */
    private function fitted(string $text, int $columnWidth = self::COLUMN_WIDTH): string
    {
        $text = Str::ascii($text);
        $limit = (int) floor(($columnWidth - (2 * self::PADDING)) / self::CHARACTER_WIDTH);

        if ($limit < 3 || strlen($text) <= $limit) {
            return $text;
        }

        return substr($text, 0, $limit - 2).'..';
    }

    /**
     * The image as a data URL, and the memory back.
     */
    private function encoded(GdImage $image): ?string
    {
        ob_start();
        $written = imagepng($image, null, 9);
        $bytes = (string) ob_get_clean();

        imagedestroy($image);

        if (! $written || $bytes === '') {
            return null;
        }

        return 'data:image/png;base64,'.base64_encode($bytes);
    }
}
