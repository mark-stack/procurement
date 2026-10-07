<?php

namespace App\Services;

use App\Enums\ProductEnums;

class ImperialSectionReader
{
    /**
     * Single purpose: read the dimensions out of an American section designation and return
     * them in millimeters.
     *
     * These arrive from SDS2, and the same designations are written in both units - "W12X26"
     * is twelve INCHES deep and "W310X39" is three hundred and ten millimeters. Nothing but
     * the size of the number separates them, so a depth under IMPERIAL_MAX_INCHES is read as
     * inches and anything above it is left to the ordinary metric patterns.
     *
     * Converted, never snapped. "L4X4X1/2" becomes a 101.6mm angle, not the 100mm angle that
     * is actually on the rack - the same rule the plate notations follow, and for the same
     * reason: the catalogue join is the user's call to make, not a guess this reader is in any
     * position to make for them. The row then reports as not found, with honest numbers
     * against it, rather than resolving to the wrong product or - worse - matching every
     * product in the category and quietly never nesting.
     */
    private const MM_PER_INCH = 25.4;

    /**
     * 1 lb/ft in kg/m. The second number of a "W12X26" is a mass per unit length.
     */
    private const KG_PER_M_PER_LB_PER_FT = 1.48816394;

    /**
     * A designation number above this is millimeters. No rolled section is five feet deep and
     * none is 60mm deep, so there is no designation this rule can read both ways.
     */
    private const IMPERIAL_MAX_INCHES = 60.0;

    /**
     * Nominal Pipe Size to the nominal bore this catalogue is indexed on (ASME B36.10 against
     * ISO 6708). A designation equivalence, in the same spirit as the nominal/actual matrices
     * in DataClassificationService - not a snap to a stocked size.
     */
    private const NOMINAL_PIPE_SIZE_TO_BORE = [
        '0.125' => 6.0,
        '0.25' => 8.0,
        '0.375' => 10.0,
        '0.5' => 15.0,
        '0.75' => 20.0,
        '1' => 25.0,
        '1.25' => 32.0,
        '1.5' => 40.0,
        '2' => 50.0,
        '2.5' => 65.0,
        '3' => 80.0,
        '3.5' => 90.0,
        '4' => 100.0,
        '5' => 125.0,
        '6' => 150.0,
        '8' => 200.0,
        '10' => 250.0,
        '12' => 300.0,
        '14' => 350.0,
        '16' => 400.0,
        '18' => 450.0,
        '20' => 500.0,
        '24' => 600.0,
    ];

    /**
     * An inch measurement: a whole number and a fraction, a bare fraction, or a decimal -
     * "1-1/2", "15 1/2", "1/2", "4", "6.625".
     *
     * Longest form FIRST. Alternation takes the first branch that lets the whole pattern
     * match, not the longest, so a decimal-first ordering read the "1" of "1/2" and stopped -
     * every fractional wall came out as a whole inch, and "L4X4X1/2" was 25.4mm thick.
     */
    private const INCHES = '\d+\s*[-\s]\s*\d+\s*\/\s*\d+|\d+\s*\/\s*\d+|\d+(?:\.\d+)?';

    public function attributes(string $text, string $productCategory): array
    {
        /**
         * Single purpose: the millimeter attributes an imperial designation carries, keyed the
         * way findGeneralProductMatches() wants them. An empty array means this text is not an
         * imperial designation for this category, and the ordinary patterns should read it.
         */
        return match ($productCategory) {
            ProductEnums::UB->value, ProductEnums::UC->value, ProductEnums::PFC->value => $this->readDepthAndMass($text),
            ProductEnums::EA->value, ProductEnums::UA->value => $this->readAngle($text),
            ProductEnums::RHS->value, ProductEnums::SHS->value => $this->readRectangularTube($text),
            ProductEnums::CHS->value => $this->readRoundTube($text),
            default => [],
        };
    }

    private function readDepthAndMass(string $text): array
    {
        /**
         * The AISC shapes whose designation is a nominal depth and a mass: W12X26 (wide
         * flange), C15X33.9 and MC18X42.7 (channel), S12X31.8 (American standard beam),
         * HP12X53 (bearing pile).
         */
        $pattern = '/\b(?:MC|HP|[WCSM])\s?(\d+(?:\.\d+)?)\s*[x*]\s*(\d+(?:\.\d+)?)/i';

        if (preg_match($pattern, $text, $matches) !== 1) {
            return [];
        }

        $depth = (float) $matches[1];

        if (! $this->isInches($depth)) {
            return [];
        }

        return [
            'nominal_height' => $this->toMillimeters($depth),
            'kg_per_m' => round((float) $matches[2] * self::KG_PER_M_PER_LB_PER_FT, 1),
        ];
    }

    private function readAngle(string $text): array
    {
        /**
         * L4X4X1/2, L6X4X1/2 - two legs and a thickness, all in inches.
         */
        $group = $this->readGroup($text, '(?:L|A)');

        if (count($group) !== 3) {
            return [];
        }

        return [
            'nominal_height' => $this->toMillimeters(max($group)),
            'nominal_width' => $this->toMillimeters($group[1]),
            'wall' => $this->toMillimeters(min($group)),
        ];
    }

    private function readRectangularTube(string $text): array
    {
        /**
         * HSS6X4X1/4, TS6X6X1/4 - two faces and a wall, all in inches.
         */
        $group = $this->readGroup($text, '(?:HSS|TS)');

        if (count($group) !== 3) {
            return [];
        }

        return [
            'nominal_height' => $this->toMillimeters(max($group)),
            'nominal_width' => $this->toMillimeters($group[1]),
            'wall' => $this->toMillimeters(min($group)),
        ];
    }

    private function readRoundTube(string $text): array
    {
        /**
         * HSS6.625X0.280 - an outside diameter and a wall, in inches - and PIPE6STD, whose
         * nominal pipe size converts to a nominal bore.
         *
         * A pipe's SCHEDULE is not read. "STD", "XS" and "XXS" each name a wall thickness
         * that varies with the size, and that is a standards table this does not carry, so a
         * pipe resolves a bore and no wall. Wall is mandatory for CHS, so the row reports as
         * not found rather than matching the wrong schedule.
         */
        if (preg_match('/\bPIPE\s?(\d+(?:\.\d+)?(?:\s*[-\s]\s*\d+\s*\/\s*\d+)?)/i', $text, $matches) === 1) {
            $bore = self::NOMINAL_PIPE_SIZE_TO_BORE[$this->formatPipeSize($this->toInches($matches[1]))] ?? null;

            return $bore === null ? [] : ['nominal_width' => $bore];
        }

        $group = $this->readGroup($text, 'HSS');

        if (count($group) !== 2) {
            return [];
        }

        return [
            'nominal_width' => $this->toMillimeters(max($group)),
            'wall' => $this->toMillimeters(min($group)),
        ];
    }

    private function readGroup(string $text, string $token): array
    {
        /**
         * Single purpose: the inch measurements of a designation's dimension group, ascending.
         * Empty unless every number in it is small enough to be inches - that is what keeps
         * the metric spelling of the same designation ("L100X100X10") out of here.
         */
        $inches = self::INCHES;
        $pattern = '/\b'.$token.'\s?('.$inches.')\s*[x*]\s*('.$inches.')(?:\s*[x*]\s*('.$inches.'))?/i';

        if (preg_match($pattern, $text, $matches) !== 1) {
            return [];
        }

        $group = [];
        foreach (array_slice($matches, 1) as $measurement) {
            if ($measurement === '') {
                continue;
            }

            $value = $this->toInches($measurement);

            if (! $this->isInches($value)) {
                return [];
            }

            $group[] = $value;
        }

        sort($group);

        return $group;
    }

    private function toInches(string $measurement): float
    {
        /**
         * "4", "6.625", "1/2", "1-1/2", "15 1/2" - a whole part, a fractional part, or both.
         */
        $measurement = trim($measurement);

        if (preg_match('/^(\d+)\s*[-\s]\s*(\d+)\s*\/\s*(\d+)$/', $measurement, $matches) === 1) {
            return (float) $matches[1] + ((float) $matches[2] / (float) $matches[3]);
        }

        if (preg_match('/^(\d+)\s*\/\s*(\d+)$/', $measurement, $matches) === 1) {
            return (float) $matches[1] / (float) $matches[2];
        }

        return (float) $measurement;
    }

    private function formatPipeSize(float $inches): string
    {
        //Keyed as the shortest decimal that represents the size, so 1.50 and 1-1/2 agree
        return rtrim(rtrim(number_format($inches, 3, '.', ''), '0'), '.');
    }

    private function isInches(float $value): bool
    {
        return $value > 0.0 && $value <= self::IMPERIAL_MAX_INCHES;
    }

    private function toMillimeters(float $inches): float
    {
        return round($inches * self::MM_PER_INCH, 1);
    }
}
