<?php

namespace App\Services\ProductImplementations;

use App\Enums\GradeEnums;
use App\Enums\MaterialEnums;
use App\Enums\MeasurementUnitEnums;
use App\Enums\ProductEnums;
use App\Enums\SupplierGroupEnums;

class RHS_Implementation extends ProductBaseImplementation
{
    public function __construct() {}

    public function productEnum(): ProductEnums
    {
        return ProductEnums::RHS;
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
                "\bRHS",    //75x50x2.5 RHS
                "(\d+)RHS", //75x50x2.5RHS
                "\s+RHS",   //75 x 50 x 2.5 RHS   75 x 50 x 2.5mm RHS
                "RHS(\d+)", //RHS75*50*2.5
                "RHS\b",    //RHS 75*50*2.5
            ],
            'nominalLengthRegex' => [

            ],
            'nominalWidthRegex' => [
                //special formula
            ],
            'nominalHeightRegex' => [
                //special formula
            ],
            'wallRegex' => [
                //special formula
            ],
            'weightRegex' => [

            ],
            'measurementUnit' => MeasurementUnitEnums::MILLIMETERS,
            'defaultMaterial' => MaterialEnums::PLAIN_CARBON_STEEL,
            //"defaultGrade" => GradeEnums::GR350,
            'supplierGroup' => SupplierGroupEnums::STEEL_MERCHANT,
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
        return $nominal_height.'x'.$nominal_width.'x'.$wall.' RHS';
    }

    public function generalProductDefinition(): array
    {
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
