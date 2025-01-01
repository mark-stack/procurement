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
//            "positiveKeyword" => [
//                //todo
//            ],
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
        ?float $actual_length,
        ?float $nominal_width,
        ?float $actual_width,
        ?float $nominal_height,
        ?float $actual_height,
        ?string $actualGrade,
        ?string $actualSurface
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
}
