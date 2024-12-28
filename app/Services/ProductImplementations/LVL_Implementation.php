<?php

namespace App\Services\ProductImplementations;

use App\Enums\MaterialEnums;
use App\Enums\MeasurementUnitEnums;
use App\Enums\ProductEnums;

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
//            "positiveKeyword" => [
//                //todo
//            ],
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
            "measurementUnit" => MeasurementUnitEnums::MILLIMETERS,
            "defaultMaterial" => MaterialEnums::TIMBER,
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

    public function formatLabel(string $productCategory, ?float $nominal_length, ?float $nominal_width, ?float $nominal_height, ?string $actualGrade, ?string $actualSurface): string
    {
        $actualSize = $nominal_height."x".$nominal_width;

        return $actualSize." ".$actualGrade.$actualSurface;
    }
}
