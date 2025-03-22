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
                "(\d+)+UC",           //3000UC
                "(\d+)+\s+UC",        //300 UC
                "UC(\d+)\*",          //UC360*57
                "universal+\s+column",
                "steel+\s+column",
            ],
            'nominalLengthRegex' => [

            ],
            'nominalWidthRegex' => [

            ],
            'nominalHeightRegex' => [
                "(\d+)+UC",      //300UC
                "(\d+)+\s+UC",   //300 UC
                "UC(\d+)\*",     //UB360*      UB360*57
            ],
            'wallRegex' => [

            ],
            'weightRegex' => [
                "UC\s+(\d+(?:\.\d+)?)", //UC 57 or 56.7   300 UC 57
                "UC(\d+(?:\.\d+)?)",    //UC57 or 56.7    300UC57
                "\*(\d+(?:\.\d+)?)",    //*57             UC360*57
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
