<?php

namespace App\Services\ProductImplementations;

use App\Enums\GradeEnums;
use App\Enums\MaterialEnums;
use App\Enums\MeasurementUnitEnums;
use App\Enums\NestingEnums;
use App\Enums\ProductEnums;
use App\Enums\SupplierGroupEnums;

class UC_Implementation extends ProductBaseImplementation
{
    public function __construct() {}

    public function productEnum(): ProductEnums
    {
        return ProductEnums::UC;
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
                "(\d+)+UC",             //3000UC
                "(\d+)+\s+UC",          //300 UC
                "UC\s?(\d+)\s?[x*]",    //UC360*57, UC360x57, UC 310x305x118
                "universal+\s+column",
                "steel+\s+column",
                /*
                 * Tekla UK, and the DIN column series Advance Steel writes. All carry their
                 * depth in millimeters, so they read like any other metric form.
                 */
                "\bUKC\s?(\d+)",        //UKC305x305x97
                "\bHE[ABM]\s?(\d+)",    //HEA300, HEB300, HEM300
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
                "UC\s?(\d+)\s?[x*]",    //UC360*      UC360*57   UC360x57   UC 310x305x118
                "\bUKC\s?(\d+)",        //UKC305x305x97
                "\bHE[ABM]\s?(\d+)",    //HEA300
                "(\d+)+UC",      //300UC
                "(\d+)+\s+UC",   //300 UC
            ],
            'wallRegex' => [

            ],
            /*
             * Two-number forms first, three-number forms last - the mass is the LAST number of
             * a three-number metric designation, which only the named (?<num>) group reaches.
             */
            'weightRegex' => [
                "UC\s+(\d+(?:\.\d+)?)", //UC 57 or 56.7   300 UC 57
                "UC(\d+(?:\.\d+)?)",    //UC57 or 56.7    300UC57
                "[x*](\d+(?:\.\d+)?)",  //*57 or x57      UC360*57   UC360x57
                "UC\s?\d+\s?[x*]\s?\d+\s?[x*]\s?(?<num>\d+(?:\.\d+)?)",     //UC 310x305x118
                "\bUKC\s?\d+\s?[x*]\s?\d+\s?[x*]\s?(?<num>\d+(?:\.\d+)?)",  //UKC305x305x97
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

        return $actualSize.'UC'.round($kg_per_m);
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
