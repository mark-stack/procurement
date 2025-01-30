<?php

namespace App\Services\ProductImplementations;

use App\Enums\GradeEnums;
use App\Enums\MaterialEnums;
use App\Enums\MeasurementUnitEnums;
use App\Enums\ProductEnums;
use App\Enums\SupplierGroupEnums;

class CSK_BOLT_Implementation extends ProductBaseImplementation
{
    public function __construct() {}

    public function productEnum(): ProductEnums
    {
        return ProductEnums::CSK_BOLT;
    }

    public function config(): array
    {
        return [
            'productCategory' => $this->productEnum()->value,
            'isFastener' => true,
            'negativeKeywords' => [
                //                "chemset",
                //                "allthread",
                //                "chemical anchor",
                //                "anchor rod",
                //                "threaded rod",
                //                "hd bolt",
            ],
            'productRegex' => [
                'countersunk',
                'countersink',
                'csk',
            ],
            'nominalLengthRegex' => [
                "x+(\d+)",      //x100
                "x+\s+(\d+)",   //x 100
                "(\d+)+\s+mm",  //1000 mm
                "(\d+)+mm",     //1000mm
            ],
            'nominalWidthRegex' => [
                "M+(\d+)", //M16
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
            'supplierGroup' => SupplierGroupEnums::FASTENERS,
        ];
    }

    public function getNominalSizeData(): array
    {
        return [
            'length' => true,
            'width' => true,
            'height' => false,
            'length_placeholder' => 'Length (mm)',
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
        //Size
        $actualSize = '';
        if ($nominal_length) {
            $actualSize = 'M'.$nominal_width.'x'.$nominal_length;
        } else {
            $actualSize = 'M'.$nominal_width;
        }

        return $actualSize.' CSK '.$actualGrade.$actualSurface;
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
                'nominal_length',
            ],
            'exclude' => [
                'precise_length',
                'precise_height',
                'precise_width',
                'wall',
                'kg_per_m',
                'nominal_height',
            ],
            'purchasableVariations' => [

            ],
        ];
    }
}
