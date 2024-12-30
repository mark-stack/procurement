<?php

namespace App\Services\ProductImplementations;

use App\Enums\MaterialEnums;
use App\Enums\MeasurementUnitEnums;
use App\Enums\ProductEnums;

class CHS_Implementation extends ProductBaseImplementation
{
    public function __construct()
    {

    }

    public function productEnum(): ProductEnums
    {
        return ProductEnums::CHS;
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
                "(\d+)CHS",      //200CHS
                "CHS(\d+)",      //CHS200
                "(\d+)+\s+CHS",  //200 CHS
                "CHS+\s+(\d+)",  //CHS 200
                "(\d+)nb",       //200nb
                "(\d+)n.b",      //200n.b
                "(\d+)+\s+n.b",  //200 n.b
            ],
            "nominalLengthRegex" => [

            ],
            "nominalWidthRegex" => [
                "(\d+)CHS",      //200CHS
                "CHS(\d+)",      //CHS200
                "(\d+)+\s+CHS",  //200 CHS
                "CHS+\s+(\d+)",  //CHS 200
                "(\d+)nb",       //200nb
                "(\d+)n.b",      //200n.b
                "(\d+)+\s+n.b",  //200 n.b
            ],
            "nominalHeightRegex" => [

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
            "width_placeholder" => "Diameter (nominal)",
            "height_placeholder" => "",
        ];
    }

    public function formatLabel(string $productCategory, ?float $nominal_length, ?float $nominal_width, ?float $nominal_height, ?string $actualGrade, ?string $actualSurface): string
    {
        $actualSize = $nominal_height;

        return $actualSize." CHS ".$actualGrade.$actualSurface;
    }
}
