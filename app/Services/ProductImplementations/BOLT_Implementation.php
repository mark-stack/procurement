<?php

namespace App\Services\ProductImplementations;

use App\Enums\MaterialEnums;
use App\Enums\MeasurementUnitEnums;
use App\Enums\ProductEnums;

class BOLT_Implementation extends ProductBaseImplementation
{
    public function __construct()
    {

    }

    public function productEnum(): ProductEnums
    {
        return ProductEnums::BOLT;
    }

    public function config(): array
    {
        return [
            "productCategory" => $this->productEnum()->value,
            "productRegex" => [
                "M+(\d+)",
                "bolt",
            ],
            "nominalLengthRegex" => [
                "x+(1?[0-9]?[0-9]|200)",      //x100  (200 or under)
                "x+\s+(1?[0-9]?[0-9]|200)",   //x 100 (200 or under)
            ],
            "nominalWidthRegex" => [
                "M+(\d+)", //M16
            ],
            "nominalHeightRegex" => [

            ],
            "measurementUnit" => MeasurementUnitEnums::MILLIMETERS,
            "defaultMaterial" => MaterialEnums::PLAIN_CARBON_STEEL,
            "negativeKeywords" => [
                "csk",
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

        return $actualSize." ".$actualGrade.$actualSurface;
    }
}
