<?php

namespace App\Services\ProductImplementations;

use App\Enums\MaterialEnums;
use App\Enums\MeasurementUnitEnums;
use App\Enums\ProductEnums;

class UB_Implementation extends ProductBaseImplementation
{
    public function __construct()
    {

    }

    public function productEnum(): ProductEnums
    {
        return ProductEnums::UB;
    }

    public function config(): array
    {
        return [
            "productCategory" => $this->productEnum()->value,
            "productRegex" => [
                "(\d+)+UB",           //300UB
                "(\d+)+\s+UB",        //300 UB
                "UB+(\d+)\b",         //UB300
                "UB+\s+(\d+)\b",      //UB 300
                "universal+\s+beam",
                "steel+\s+beam",
            ],
            "nominalLengthRegex" => [

            ],
            "nominalWidthRegex" => [

            ],
            "nominalHeightRegex" => [
                "(\d+)+UB",      //300UB
                "(\d+)+\s+UB",   //300 UB
                "UB+(\d+)\b",    //UB300
                "UB+\s+(\d+)\b", //UB 300
            ],
            "measurementUnit" => MeasurementUnitEnums::MILLIMETERS,
            "defaultMaterial" => MaterialEnums::PLAIN_CARBON_STEEL,
            "negativeKeywords" => [
                //todo
            ],
        ];
    }


    public function getNominalSizeData(): array
    {
        return [
            "length" => false,
            "width" => false,
            "height" => true,
            "length_placeholder" => "",
            "width_placeholder" => "",
            "height_placeholder" => "Height (nominal)",
        ];
    }

    public function formatLabel(string $productCategory, ?float $nominal_length, ?float $nominal_width, ?float $nominal_height, ?string $actualGrade, ?string $actualSurface): string
    {
        $actualSize = $nominal_height;

        return $actualSize." ".$actualGrade.$actualSurface;
    }
}
