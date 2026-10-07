<?php

namespace App\Services\ProductImplementations;

use App\Enums\GradeEnums;
use App\Enums\MaterialEnums;
use App\Enums\MeasurementUnitEnums;
use App\Enums\NestingEnums;
use App\Enums\ProductEnums;
use App\Enums\SupplierGroupEnums;

class PLATE_Implementation extends ProductBaseImplementation
{
    public function __construct() {}

    public function productEnum(): ProductEnums
    {
        return ProductEnums::PLATE;
    }

    public function config(): array
    {
        return [
            'productCategory' => $this->productEnum()->value,
            'isFastener' => false,
            'negativeKeywords' => [
                /*
                 * SDS2's round plate (RPL3/8x1-2) is a thickness and a diameter, and its bent
                 * plate (BPL3/8x1-0) has a developed length its bounding box does not describe.
                 * Neither nests as the rectangle the AREA algorithm would make of it, so they
                 * are refused rather than read. The leading-PL patterns below already miss them
                 * on the word boundary - these keywords are the guard that outlives the next
                 * person to widen one of those patterns.
                 */
                'RPL',
                'BPL',
                /*
                 * An unresolved CAD placeholder - "PL %Thicknessx%Width" is a template that was
                 * exported before it was filled in. There is no thickness to find.
                 */
                '%Thickness',
            ],
            'productRegex' => [
                'Plate',                //plate
                'Plates',               //plates
                "\b(\d+)+PL",             //20PL
                "\b(\d+)+\s+PL",          //20 PL
                "\b(\d+)+mm+\s+PL",       //20mm PL
                "\b(\d+)+mm+\s+plate",    //20mm plate
                "\bPLT(\d+)\b",           //PLT8
                "Steel+\s+Plate",       //Steel plate
                "Steel+\s+Plates",      //steel plates
                "(\d+)+mm\b.*\b(1200|1220|1500|1800|2400|2440|3000|3100|3200)",    //20mm plus one of 1200|1220|1500|1800|2400|2440|3000|3100|3200
                "(\d+)+\s+mm\b.*\b(1200|1220|1500|1800|2400|2440|3000|3100|3200)", //20 mm plus one of 1200|1220|1500|1800|2400|2440|3000|3100|3200
                /*
                 * Thickness AFTER the token. Every pattern above reads the Australian trailing
                 * form ("20PL"), and this one reads the leading form, which is what Tekla,
                 * Advance Steel, SDS2 and most generic fabrication exports write: PL10,
                 * PL10*500*1000, PL 20x620x500, PL3/8x1-0, PL 10GA, PL-10-500-1000, S355 PL10.
                 * The word boundary is load-bearing - it is what keeps RPL, BPL and a part code
                 * like "H2C20PL20" out.
                 */
                "\bPL[-\s]?(\d+)",
                /*
                 * Imperial written thickness-first with the plate token trailing, which the
                 * pattern above cannot reach: 1/4" x 4' x 8' PL
                 */
                "\d+\s?\/\s?\d+\s?\"?.*\bPL\b",
                /*
                 * Property-driven CAD and sheet-metal BOMs, which name no profile at all - the
                 * thickness and the flat extents arrive as separate properties. Two properties
                 * are required, because "T=10" on its own says nothing about plate.
                 */
                "\bT\s?=\s?\d+(\.\d+)?\s?;.*\b[LW]\s?=\s?\d",                 //T=10; L=1000; W=500
                "\bThickness\s?=\s?\d+(\.\d+)?.*(Bounding\s?Box|FlatPattern)", //Thickness=10; Bounding Box=500x1000
                "(\d+)\s?mm\s?\/\s?\d+\s?\/\s?\d+",                            //10mm / 500 / 1000
            ],
            'nominalLengthRegex' => [

            ],
            'nominalWidthRegex' => [
                "(1200|1220|1800|2400|2440|3000|3200|1\.2|1\.8|1\.22|2\.4|2\.44|3\.0|3\.2)", //Find common plate widths in M or MM
            ],
            /*
             * Thickness is the only plate attribute the catalogue is joined on, and three of
             * the notations it arrives in cannot be read as a plain millimeter number: a gauge,
             * an imperial fraction, and the leading-PL form whose hyphen separator the shared
             * number extraction reads as a minus sign. DataClassificationService::findPlateThickness()
             * settles those three first and falls back to these patterns for everything else.
             */
            'nominalHeightRegex' => [
                "\b(0|[1-9][0-9]?|1[0-4][0-9]|150) ?PL", //16PL or 16 PL
                "\b(0|[1-9][0-9]?|1[0-4][0-9]|150) ?mm", //16mm or 16 mm
                "PLT(\d+)\*",                            //PLT10*234
                "\bT\s?=\s?(\d+(\.\d+)?)",               //T=10; L=1000; W=500
                "\bThickness\s?=\s?(\d+(\.\d+)?)",       //Thickness=10; Bounding Box=500x1000
            ],
            'wallRegex' => [

            ],
            'weightRegex' => [

            ],
            'measurementUnit' => MeasurementUnitEnums::MILLIMETERS,
            'defaultMaterial' => MaterialEnums::PLAIN_CARBON_STEEL,
            //"defaultGrade" => GradeEnums::NONE, //todo
            'supplierGroup' => SupplierGroupEnums::PROFILE_CUTTING,
            "algorithm" => NestingEnums::AREA,
        ];
    }

    public function getNominalSizeData(): array
    {
        return [
            'length' => false,
            'width' => false,
            'height' => true,
            'length_placeholder' => '',
            'width_placeholder' => '',
            'height_placeholder' => 'Thickness (mm)',
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
        $actualSize = $nominal_height.'PL';

        return $actualSize.' '.$actualGrade;
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
                'nominal_height',
            ],
            'exclude' => [
                'precise_length',
                'precise_height',
                'precise_width',
                'wall',
                'kg_per_m',
            ],
            'purchasableVariations' => [
                'nominal_width',
                'nominal_length',
            ],
        ];
    }
}
