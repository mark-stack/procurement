<?php

namespace App\Services;

/**
 * A number as a detailer wrote it in a spreadsheet cell, with the unit the cell declared.
 *
 * Every figure the importer reads off a bill of materials arrives as whatever the person who made
 * the sheet typed, and the old reading of it was to strip the letters out and cast to float. That is
 * right for "9000mm" and silently wrong for most of the other ways a length gets written:
 *
 *  - "12,500" cast to 12.5, because PHP stops a float at the comma. Half a metre of steel, gone.
 *  - "9,5" - a decimal comma, which is how most of Europe writes 9.5 - also cast to 9.
 *  - "3'-6"" cast to 3, so three feet six inches became three of whatever the column was in.
 *  - "1 1/2" cast to 1, losing the half.
 *  - "1.2e3" had its "e" stripped and became 1.23.
 *
 * None of those raised anything. The row imported, at a length nobody had written down.
 *
 * So the cell is parsed rather than cast. Separators are worked out from where they sit, fractions
 * and feet-and-inches are read as the measurements they are, and a cell that names its own unit is
 * believed - which is the one piece of information the old reading threw away, and the only thing
 * that can settle a figure the magnitude rule has to guess at. See CsvService::normalisedLength().
 */
final class SheetNumber
{
    private const MM_PER_INCH = 25.4;

    private const MM_PER_FOOT = 304.8;

    private const MM_PER_CM = 10.0;

    /*
     * The spaces a spreadsheet uses as a thousands separator, which are not the space key: a
     * non-breaking space, a thin space and a narrow no-break space all arrive out of Excel's own
     * number formatting.
     */
    private const SPACES = ["\u{a0}", "\u{2007}", "\u{2009}", "\u{202f}"];

    /**
     * @param  float  $value  The figure, in $units where the cell named one
     * @param  'm'|'mm'|null  $units  What the cell said it was in, or null when it said nothing
     */
    private function __construct(
        public readonly float $value,
        public readonly ?string $units,
    ) {}

    /**
     * The number in this cell, or null if there is not one in it. "-", "N/A" and "#REF!" are all
     * cells with no number in them, and each is a row the importer cannot give a length.
     */
    public static function tryFrom(?string $raw): ?self
    {
        $text = self::cleaned($raw);

        if ($text === '') {
            return null;
        }

        //Feet and inches are a measurement rather than a figure with a unit after it
        if ($imperial = self::imperial($text)) {
            return $imperial;
        }

        $units = self::units($text);
        $value = self::figure($text);

        if ($value === null) {
            return null;
        }

        //Centimetres are read as the millimetres they are, so nothing below here has a third unit
        if ($units === 'cm') {
            return new self($value * self::MM_PER_CM, 'mm');
        }

        return new self($value, $units);
    }

    /**
     * The figure alone, with no unit, for the cells where the unit is not the question - a
     * quantity. Zero for a cell with no number in it, which is what the caller substitutes one for.
     */
    public static function quantity(?string $raw): float
    {
        return self::tryFrom($raw)->value ?? 0.0;
    }

    /**
     * The currency symbols and the odd spaces out, and nothing else: stripping letters is what this
     * class exists not to do.
     */
    private static function cleaned(?string $raw): string
    {
        $text = str_replace(self::SPACES, ' ', (string) $raw);

        $text = (string) preg_replace('/[€£¥₹$¢₱₽₩₦฿]/u', '', $text);

        return trim($text);
    }

    /**
     * Feet and inches, in millimetres, for the notations that carry their own marks: "3'-6"",
     * "3' 6 1/2"", "48"", "4 ft". Read as millimetres rather than as a bare number because a
     * measurement in inches is not a figure in the column's own unit - there is nothing left for
     * the magnitude rule to decide.
     *
     * A fraction with no mark against it is not imperial. "1 1/2" in a column of metres is a metre
     * and a half, and guessing inches there would turn it into 38mm.
     */
    private static function imperial(string $text): ?self
    {
        if (! preg_match('/[\'"]|\b(?:ft|feet|in|inch|inches)\b/i', $text)) {
            return null;
        }

        $millimetres = 0.0;
        $found = false;

        //Feet: 3', 3 ft, 3 feet
        if (preg_match('/(\d+(?:\.\d+)?)\s*(?:\'|ft\b|feet\b)/i', $text, $matches) === 1) {
            $millimetres += (float) $matches[1] * self::MM_PER_FOOT;
            $found = true;
        }

        /*
         * Inches, taken from after the feet mark where there is one, so the 3 in "3'-6"" cannot be
         * read as the inches as well as the feet.
         */
        $inchesFrom = $found ? (string) preg_replace('/^.*?(?:\'|ft\b|feet\b)/is', '', $text) : $text;

        if ($inches = self::inches($inchesFrom)) {
            $millimetres += $inches * self::MM_PER_INCH;
            $found = true;
        }

        if (! $found || $millimetres <= 0.0) {
            return null;
        }

        return new self($millimetres, 'mm');
    }

    /**
     * Inches out of a fragment that has already had any feet taken off it: "6 1/2"", "1/2"", "48"",
     * "6 in".
     */
    private static function inches(string $text): ?float
    {
        //A whole number and a fraction: 6 1/2"
        if (preg_match('/(\d+)\s+(\d+)\s*\/\s*(\d+)\s*(?:"|in\b|inch)/i', $text, $matches) === 1
            && (float) $matches[3] > 0.0) {
            return (float) $matches[1] + ((float) $matches[2] / (float) $matches[3]);
        }

        //A fraction alone: 1/2"
        if (preg_match('/(\d+)\s*\/\s*(\d+)\s*(?:"|in\b|inch)/i', $text, $matches) === 1
            && (float) $matches[2] > 0.0) {
            return (float) $matches[1] / (float) $matches[2];
        }

        //A plain figure: 48", 6 in
        if (preg_match('/(\d+(?:\.\d+)?)\s*(?:"|in\b|inch)/i', $text, $matches) === 1) {
            return (float) $matches[1];
        }

        return null;
    }

    /**
     * What unit the cell named, if it named one.
     *
     * The lookarounds are doing the work a word boundary cannot: there is no boundary between the
     * "9" and the "m" in "9m", and "mm" has to be settled before "m" or every millimetre reads as
     * a metre.
     *
     * @return 'm'|'mm'|'cm'|null
     */
    private static function units(string $text): ?string
    {
        foreach ([
            'mm' => '/(?<![a-z])(?:mm|millimet(?:re|er)s?)(?![a-z])/i',
            'cm' => '/(?<![a-z])(?:cm|centimet(?:re|er)s?)(?![a-z])/i',
            'm' => '/(?<![a-z])(?:m|met(?:re|er)s?|lm)(?![a-z])/i',
        ] as $unit => $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return $unit;
            }
        }

        return null;
    }

    /**
     * The first figure in the cell, separators and fractions and all.
     */
    private static function figure(string $text): ?float
    {
        //A fraction with no unit mark: a fraction of whatever the column is in
        if (preg_match('/(?<![\d.,])(?:(\d+)\s+)?(\d+)\s*\/\s*(\d+)(?![\d.,])/', $text, $matches) === 1
            && (float) $matches[3] > 0.0) {
            return (float) ($matches[1] ?: 0) + ((float) $matches[2] / (float) $matches[3]);
        }

        /*
         * A run of digits with whatever separators are inside it. Taken whole rather than up to the
         * first separator, which is the whole point: "12,500" is one figure and not a 12.
         */
        if (preg_match('/-?\d[\d., ]*\d|-?\d/', $text, $matches) !== 1) {
            return null;
        }

        return (float) self::separated(trim($matches[0]));
    }

    /**
     * One figure's separators resolved to a plain decimal point.
     *
     * Which separator means what is decided by where it sits, because no sheet says. The rules are
     * the ones a person reads it by: where both a comma and a point appear, the rightmost is the
     * decimal and the other is grouping; where only one appears once, three digits after it make it
     * grouping and anything else makes it the decimal; and a separator appearing more than once is
     * grouping whichever it is.
     */
    private static function separated(string $figure): string
    {
        $figure = str_replace(' ', '', $figure);

        $lastComma = strrpos($figure, ',');
        $lastPoint = strrpos($figure, '.');

        //Both: the rightmost is the decimal point and everything else is grouping
        if ($lastComma !== false && $lastPoint !== false) {
            $decimal = $lastComma > $lastPoint ? ',' : '.';
            $grouping = $decimal === ',' ? '.' : ',';

            return str_replace($decimal, '.', str_replace($grouping, '', $figure));
        }

        $separator = $lastComma !== false ? ',' : ($lastPoint !== false ? '.' : null);

        if ($separator === null) {
            return $figure;
        }

        //More than one of them, so it cannot be the decimal point: "1,234,567"
        if (substr_count($figure, $separator) > 1) {
            return str_replace($separator, '', $figure);
        }

        /*
         * Exactly three digits after it, with digits before it: grouping. "12,500" is twelve and a
         * half thousand, and reading it as 12.5 is the bug this class was written for. A sheet
         * written in metres that means 12.5 arrives at the same millimetre figure either way, which
         * is why this reading is the safe one to prefer.
         */
        $after = strlen($figure) - (int) strrpos($figure, $separator) - 1;
        $before = (int) strrpos($figure, $separator) - ($figure[0] === '-' ? 1 : 0);

        if ($after === 3 && $before > 0) {
            return str_replace($separator, '', $figure);
        }

        return str_replace($separator, '.', $figure);
    }
}
