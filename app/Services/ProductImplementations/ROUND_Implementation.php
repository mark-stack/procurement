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
            'productRegex' => [
                "D(\d+)\b",         //"D20"
                "round\b",          //"20mm round", "20mm round bar"
                "Ø(\d+)+\s+bar",    //"Ø20 bar"
                "Ø(\d+)+\s+round",  //"Ø20 round"
                "Ø(\d+)mm+\s+bar",  //"Ø20mm bar"
                "Ø(\d+)mm+\s+round",  //"Ø20mm bar"
            ],
            'nominalLengthRegex' => [

            ],
            'nominalWidthRegex' => [
                "D(\d+)\b",         //"D20"
                "round\b",          //"20mm round", "20mm round bar"
                "Ø(\d+)+\s+bar",    //"Ø20 bar"
                "Ø(\d+)+\s+round",  //"Ø20 round"
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
