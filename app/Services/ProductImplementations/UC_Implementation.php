<?php

namespace App\Services\ProductImplementations;

use App\Enums\MaterialEnums;
use App\Enums\MeasurementUnitEnums;
use App\Enums\ProductEnums;

class UC_Implementation extends ProductBaseImplementation
{
    public function __construct()
    {

    }

    public function productEnum(): ProductEnums
    {
        return ProductEnums::UC;
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
                "(\d+)+UC",           //3000UC
                "(\d+)+\s+UC",        //300 UC
                "UC+(\d+)\b",         //UC300
                "UC+\s+(\d+)\b",      //UC 300
                "universal+\s+column",
                "steel+\s+column",
            ],
            "nominalLengthRegex" => [

            ],
            "nominalWidthRegex" => [

            ],
            "nominalHeightRegex" => [
                "(\d+)+UC",      //300UC
                "(\d+)+\s+UC",   //300 UC
                "UC+(\d+)\b",    //UC300
                "UC+\s+(\d+)\b", //UC 300
            ],
            "measurementUnit" => MeasurementUnitEnums::MILLIMETERS,
            "defaultMaterial" => MaterialEnums::PLAIN_CARBON_STEEL,
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
