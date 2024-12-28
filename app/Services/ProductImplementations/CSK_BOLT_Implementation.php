<?php

namespace App\Services\ProductImplementations;

use App\Enums\MaterialEnums;
use App\Enums\MeasurementUnitEnums;
use App\Enums\ProductEnums;

class CSK_BOLT_Implementation extends ProductBaseImplementation
{
    public function __construct()
    {

    }

    public function productEnum(): ProductEnums
    {
        return ProductEnums::CSK_BOLT;
    }

    public function config(): array
    {
        return [
            "productCategory" => $this->productEnum()->value,
            "productRegex" => [
                "M+(\d+)",      //M12
                "countersunk",
                "countersink",
                "csk",
            ],
            "nominalLengthRegex" => [
                "x+(\d+)",      //x100
                "x+\s+(\d+)",   //x 100
                "(\d+)+\s+mm",  //1000 mm
                "(\d+)+mm",     //1000mm
            ],
            "nominalWidthRegex" => [
                "M+(\d+)", //M16
            ],
            "nominalHeightRegex" => [

            ],
            "measurementUnit" => MeasurementUnitEnums::MILLIMETERS,
            "defaultMaterial" => MaterialEnums::PLAIN_CARBON_STEEL,
            "negativeKeywords" => [
                "chemset",
                "allthread",
                "chemical anchor",
                "anchor rod",
                "threaded rod",
                "hd bolt",
            ],
        ];
    }


    public function getNominalSizeData(): array
    {
        return [
            "length" => true,
            "width" => true,
            "height" => false,
            "length_placeholder" => "Length (mm)",
            "width_placeholder" => "Diameter (mm)",
            "height_placeholder" => "",
        ];
    }

    public function formatLabel(string $productCategory, ?float $nominal_length, ?float $nominal_width, ?float $nominal_height, ?string $actualGrade, ?string $actualSurface): string
    {
        //Size
        $actualSize = "";
        if ($nominal_length) {
            $actualSize = "M".$nominal_width."x".$nominal_length;
        } else {
            $actualSize = "M".$nominal_width;
        }

        return $actualSize." CSK ".$actualGrade.$actualSurface;
    }
}
