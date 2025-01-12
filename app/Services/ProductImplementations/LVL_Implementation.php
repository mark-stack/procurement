<?php

namespace App\Services\ProductImplementations;

use App\Enums\GradeEnums;
use App\Enums\MaterialEnums;
use App\Enums\MeasurementUnitEnums;
use App\Enums\ProductEnums;
use App\Enums\SupplierGroupEnums;

class LVL_Implementation extends ProductBaseImplementation
{
    public function __construct()
    {

    }

    public function productEnum(): ProductEnums
    {
        return ProductEnums::LVL;
    }

    public function config(): array
    {
        return [
            "productCategory" => $this->productEnum()->value,
            "isFastener" => false,
            "negativeKeywords" => [
                //
            ],
            "productRegex" => [
                "LVL",
            ],
            "nominalLengthRegex" => [

            ],
            "nominalWidthRegex" => [
                "x+(\d+)",      //x100
                "X+\s+(\d+)",   //x 100
            ],
            "nominalHeightRegex" => [
                "(\d+)+x",      //100x
                "(\d+)+\s+X",   //100 x
            ],
            "wallRegex" => [

            ],
            "weightRegex" => [

            ],
            "measurementUnit" => MeasurementUnitEnums::MILLIMETERS,
            "defaultMaterial" => MaterialEnums::TIMBER,
            //"defaultGrade" => GradeEnums::NONE, //todo
            "supplierGroup" => SupplierGroupEnums::TIMBER_MERCHANT,
        ];
    }


    public function getNominalSizeData(): array
    {
        return [
            "length" => false,
            "width" => true,
            "height" => true,
            "length_placeholder" => "",
            "width_placeholder" => "Width (mm)",
            "height_placeholder" => "Height (mm)",
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
    ): string
    {
        $actualSize = $nominal_height."x".$nominal_width;

        return $actualSize." ".$actualGrade.$actualSurface;
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
            "mandatory" => [
                'product_category',
                'material',
                'grade',
                'surface',
                'nominal_units',
                "nominal_width",
                'nominal_height',
            ],
            "exclude" => [
                "wall",
                "kg_per_m",
                "precise_length",
                "precise_height",
                "precise_width",
            ],
            "purchasableVariations" => [
                "nominal_length",
            ],
        ];
    }
}
