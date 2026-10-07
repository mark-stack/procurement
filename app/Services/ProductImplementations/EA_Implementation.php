<?php

namespace App\Services\ProductImplementations;

use App\Enums\GradeEnums;
use App\Enums\MaterialEnums;
use App\Enums\MeasurementUnitEnums;
use App\Enums\NestingEnums;
use App\Enums\ProductEnums;
use App\Enums\SupplierGroupEnums;

class EA_Implementation extends ProductBaseImplementation
{
    public function __construct() {}

    public function productEnum(): ProductEnums
    {
        return ProductEnums::EA;
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
             * Every one of these requires a dimension group, and "EA" requires a word
             * boundary in front of it. Both of those are load-bearing.
             *
             * "EA(\d+)" with no boundary made an angle of anything ending in EA followed by a
             * number - "HEA300" was a 300mm angle, so was "AREA100", and because the first
             * matching category used to win, "150PFC AREA200" was an angle rather than a
             * channel. And "\b(\d+)+\s+EA" with no dimension group made an angle out of "4 EA"
             * where EA is the unit "each", which is how most American BOMs write a quantity.
             */
            'productRegex' => [
                "\bEA\s?\d+(\.\d+)?\s*[x*]\s*\d+",                                          //EA100*100*10
                "\d+(\.\d+)?\s*[x*]\s*\d+(\.\d+)?\s*[x*]\s*\d+(\.\d+)?\s*(mm)?\s*EA\b",     //100x100x10 EA
                "\d+(\.\d+)?\s*[x*]\s*\d+(\.\d+)?\s*(mm)?\s*EA\b",                          //100 x 100mm EA
                "equal+\s+angle",
                /*
                 * The AISC angle, which is what SDS2 exports, and the Advance Steel one. Both
                 * write an equal and an unequal angle identically, so the backreference is
                 * what separates them: \1 requires the second leg to repeat the first. The
                 * matching negative lookahead in UA_Implementation claims the other case.
                 */
                "\bL\s?(\d+(?:\.\d+)?)\s*[x*]\s*\\1\s*[x*]",    //L100X100X10, L4X4X1/2
                "\bA\s?(\d+(?:\.\d+)?)\s*[x*]\s*\\1\s*[x*]",    //A100x100x10
            ],
            'nominalLengthRegex' => [

            ],
            'nominalWidthRegex' => [
                //uses special rule
            ],
            'nominalHeightRegex' => [
                //uses special rule
            ],
            'wallRegex' => [
                //uses special rule
            ],
            'weightRegex' => [

            ],
            'measurementUnit' => MeasurementUnitEnums::MILLIMETERS,
            'defaultMaterial' => MaterialEnums::PLAIN_CARBON_STEEL,
            //"defaultGrade" => GradeEnums::NONE, //todo
            'supplierGroup' => SupplierGroupEnums::STEEL_MERCHANT, //todo
            "algorithm" => NestingEnums::METERAGE,
        ];
    }

    public function getNominalSizeData(): array
    {
        return [
            'length' => false,
            'width' => true,
            'height' => true,
            'length_placeholder' => '',
            'width_placeholder' => 'Width (mm)',
            'height_placeholder' => 'Height (mm)',
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
        return $nominal_height.'x'.$nominal_width.'x'.$wall.' EA';
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
                'nominal_height',
                'wall',
            ],
            'exclude' => [
                'kg_per_m',
                'precise_length',
                'precise_height',
                'precise_width',
            ],
            'purchasableVariations' => [
                'nominal_length',
            ],
        ];
    }
}
