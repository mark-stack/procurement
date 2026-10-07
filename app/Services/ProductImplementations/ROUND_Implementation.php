<?php

namespace App\Services\ProductImplementations;

use App\Enums\GradeEnums;
use App\Enums\MaterialEnums;
use App\Enums\MeasurementUnitEnums;
use App\Enums\NestingEnums;
use App\Enums\ProductEnums;
use App\Enums\SupplierGroupEnums;

class ROUND_Implementation extends ProductBaseImplementation
{
    public function __construct() {}

    public function productEnum(): ProductEnums
    {
        return ProductEnums::ROUND;
    }

    public function config(): array
    {
        return [
            'productCategory' => $this->productEnum()->value,
            'isFastener' => false,
            'negativeKeywords' => [
                //
            ],
            /*
             * A bare "R20" is deliberately absent. Advance Steel writes a round bar that way,
             * but so does a drawing revision and so does a bend radius, and a classifier that
             * guesses wrong is worse than one that leaves the row for the user. "ROD20", "Ø20"
             * and "20 DIA" are unambiguous, so those are read.
             */
            'productRegex' => [
                "D(\d+)\b",         //"D20"
                "round\b",          //"20mm round", "20mm round bar"
                "Ø(\d+)+\s+bar",    //"Ø20 bar"
                "Ø(\d+)+\s+round",  //"Ø20 round"
                "Ø(\d+)mm+\s+bar",  //"Ø20mm bar"
                "Ø(\d+)mm+\s+round",  //"Ø20mm bar"
                /*
                 * A bare diameter, anchored to the WHOLE descriptor. The symbol on its own
                 * just means "diameter" and says nothing about the profile, so unanchored it
                 * claimed the actual diameter of a pipe: "150nb (Ø168.3)" scored as round bar
                 * against the CHS its "nb" names.
                 *
                 * No \b in front of the symbol either - it is multi-byte, and a word boundary
                 * against its first byte never matches.
                 */
                "^\s*Ø\s?\d+(\.\d+)?\s*(mm)?\s*$",      //"Ø20"
                "\bROD\s?(\d+(\.\d+)?)",    //"ROD20"
                "(\d+)\s*(mm)?\s*dia\b",    //"20 DIA ROUND BAR", "20mm dia"
            ],
            'nominalLengthRegex' => [

            ],
            'nominalWidthRegex' => [
                "D(\d+)\b",         //"D20"
                "round\b",          //"20mm round", "20mm round bar"
                "Ø(\d+)+\s+bar",    //"Ø20 bar"
                "Ø(\d+)+\s+round",  //"Ø20 round"
                /*
                 * "round\b" above matches the word and carries no number with it, so the
                 * diameter of a plainly-written "20mm round bar" came back null - the one
                 * attribute the catalogue joins a round bar on.
                 */
                "(\d+(\.\d+)?)\s*mm\s*round\b",     //"20mm round", "20mm round bar"
                "Ø\s?(\d+(\.\d+)?)",                //"Ø20"
                "\bROD\s?(\d+(\.\d+)?)",            //"ROD20"
                "(\d+(\.\d+)?)\s*(mm)?\s*dia\b",    //"20 DIA ROUND BAR"
            ],
            'nominalHeightRegex' => [

            ],
            'wallRegex' => [

            ],
            'weightRegex' => [

            ],
            'measurementUnit' => MeasurementUnitEnums::MILLIMETERS,
            'defaultMaterial' => MaterialEnums::PLAIN_CARBON_STEEL,
            //"defaultGrade" => GradeEnums::NONE, //todo
            'supplierGroup' => SupplierGroupEnums::STEEL_MERCHANT,
            "algorithm" => NestingEnums::METERAGE,
        ];
    }

    public function getNominalSizeData(): array
    {
        return [
            'length' => false,
            'width' => true,
            'height' => false,
            'length_placeholder' => '',
            'width_placeholder' => 'Diameter (mm)',
            'height_placeholder' => '',
        ];
    }

    public function formatLabel(
        string $productCategory,
        ?float $nominal_length,
        ?float $precise_length,
        ?float $nominal_width,
        ?float $precise_width,
        ?float $nominal_height,
        ?float $precise_height,
        ?string $actualGrade,
        ?string $actualSurface,
        ?float $wall,
        ?float $kg_per_m,
        ?string $material,
    ): string {
        $actualSize = $nominal_width;

        return $actualSize.' ROUND BAR';
    }

    public function generalProductDefinition(): array
    {
        //        'product_category',
        //        'material',
        //        'grade',
        //        'surface',
        //        'nominal_units',
        //        "nominal_length",
        //        "precise_length",
        //        "nominal_width",
        //        "precise_width",
        //        'nominal_height',
        //        "precise_height",
        //        "wall",
        //        "kg_per_m"

        return [
            'mandatory' => [
                'product_category',
                'material',
                'grade',
                'surface',
                'nominal_units',
                'nominal_width',
            ],
            'exclude' => [
                'nominal_height',
                'precise_length',
                'precise_height',
                'precise_width',
                'wall',
                'kg_per_m',
            ],
            'purchasableVariations' => [
                'nominal_length',
            ],
        ];
    }
}
