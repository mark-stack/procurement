<?php

namespace App\Services\ProductImplementations;

use App\Enums\MaterialEnums;
use App\Enums\MeasurementUnitEnums;
use App\Enums\ProductEnums;

class NUT_Implementation extends ProductBaseImplementation
{
    public function __construct()
    {

    }

    public function productEnum(): ProductEnums
    {
        return ProductEnums::NUT;
    }

    public function config(): array
    {
        return [
            "productCategory" => $this->productEnum()->value,
            "isFastener" => true,
            "negativeKeywords" => [

            ],
            "productRegex" => [
                "nut",
            ],
            "nominalLengthRegex" => [
                //
            ],
            "nominalWidthRegex" => [
                "M+(\d+)", //M16
            ],
            "nominalHeightRegex" => [
                //
            ],
            "wallRegex" => [

            ],
            "weightRegex" => [

            ],
            "measurementUnit" => MeasurementUnitEnums::MILLIMETERS,
            "defaultMaterial" => MaterialEnums::PLAIN_CARBON_STEEL,
        ];
    }

    public function getNominalSizeData(): array
    {
        return [
            "length" => false,
            "width" => true,
            "height" => false,
            "length_placeholder" => "",
            "width_placeholder" => "Diameter (mm)",
            "height_placeholder" => "",
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
        //Size
        $actualSize = "";
        if ($nominal_length) {
            $actualSize = "M".$nominal_width."x".$nominal_length;
        } else {
            $actualSize = "M".$nominal_width;
        }

        return $actualSize." NUT. ".$actualGrade.$actualSurface;
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
                'nominal_height',
                "nominal_width",
            ],
            "exclude" => [
                "precise_length",
                "precise_height",
                "precise_width",
                "wall",
                "kg_per_m",
                "nominal_length",
            ],
            "purchasableVariations" => [

            ],
        ];
    }
}
