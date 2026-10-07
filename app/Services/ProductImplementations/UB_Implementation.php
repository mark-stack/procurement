<?php

namespace App\Services\ProductImplementations;

use App\Enums\GradeEnums;
use App\Enums\MaterialEnums;
use App\Enums\MeasurementUnitEnums;
use App\Enums\NestingEnums;
use App\Enums\ProductEnums;
use App\Enums\SupplierGroupEnums;

class UB_Implementation extends ProductBaseImplementation
{
    public function __construct() {}

    public function productEnum(): ProductEnums
    {
        return ProductEnums::UB;
    }

    public function config(): array
    {
        return [
            'productCategory' => $this->productEnum()->value,
            'isFastener' => false,
            'negativeKeywords' => [
                //
            ],
            'productRegex' => [
                "(\d+)+UB",             //300UB
                "(\d+)+\s+UB",          //300 UB
                "UB\s?(\d+)\s?[x*]",    //UB360*57, UB360x57, UB 310x165x40
                "universal+\s+beam",
                "steel+\s+beam",
                /*
                 * Tekla UK and the DIN beam Advance Steel writes. Both carry their depth in
                 * millimeters, so they read like any other metric form.
                 */
                "\bUKB\s?(\d+)",    //UKB305x165x40
                "\bIPE\s?(\d+)",    //IPE300
                /*
                 * The AISC wide flange, which is what SDS2 exports. Both the imperial
                 * ("W12X26" - 12 inches deep, 26 lb/ft) and metric ("W310X39") designations
                 * are written the same way; DataClassificationService tells them apart by the
                 * size of the depth and converts the imperial one.
                 */
                "\bW\d+(\.\d+)?\s?[xX*]\s?\d+(\.\d+)?",
            ],
            'nominalLengthRegex' => [

            ],
            'nominalWidthRegex' => [

            ],
            /*
             * Leading-token forms first, trailing-token forms last, because the last pattern
             * to match wins - see DataClassificationService::findNumberByRegexPatterns().
             */
            'nominalHeightRegex' => [
                "UB\s?(\d+)\s?[x*]",    //UB360*      UB360*57   UB360x57   UB 310x165x40
                "\bUKB\s?(\d+)",        //UKB305x165x40
                "\bIPE\s?(\d+)",        //IPE300
                //The metric AISC wide flange. Three digits or more, because two is the inch
                //spelling of the same designation and ImperialSectionReader converts that
                "\bW([1-9][0-9]{2,})\s?[x*]",   //W310X39
                "(\d+)+UB",      //300UB       300UB57
                "(\d+)+\s+UB",   //300 UB      300 UB 57
            ],
            'wallRegex' => [

            ],
            /*
             * Two-number forms first, three-number forms last. The mass is the LAST number of
             * a three-number metric designation, and "the first number in the match" cannot
             * reach it - hence the named (?<num>) group, which is read in preference to the
             * match when a pattern declares one.
             */
            'weightRegex' => [
                "UB\s+(\d+(?:\.\d+)?)", //UB 57 or 56.7   300 UB 57
                "UB(\d+(?:\.\d+)?)",    //UB57 or 56.7    300UB57
                "[x*](\d+(?:\.\d+)?)",  //*57 or x57      UB360*57   UB360x57
                "UB\s?\d+\s?[x*]\s?\d+\s?[x*]\s?(?<num>\d+(?:\.\d+)?)",     //UB 310x165x40
                "\bUKB\s?\d+\s?[x*]\s?\d+\s?[x*]\s?(?<num>\d+(?:\.\d+)?)",  //UKB305x165x40
                "\bW[1-9][0-9]{2,}\s?[x*]\s?(?<num>\d+(?:\.\d+)?)",         //W310X39
            ],
            'measurementUnit' => MeasurementUnitEnums::MILLIMETERS,
            'defaultMaterial' => MaterialEnums::PLAIN_CARBON_STEEL,
            //"defaultGrade" => GradeEnums::GR300,
            'supplierGroup' => SupplierGroupEnums::STEEL_MERCHANT,
            "algorithm" => NestingEnums::METERAGE,
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
            'height_placeholder' => 'Height (nominal)',
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
        $actualSize = $nominal_height;

        return $actualSize.'UB'.round($kg_per_m);
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
                'kg_per_m',
            ],
            'exclude' => [
                'nominal_width',
                'precise_length',
                'precise_height',
                'precise_width',
                'wall',
            ],
            'purchasableVariations' => [
                'nominal_length',
            ],
        ];
    }
}
