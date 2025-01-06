<?php

namespace App\Services\ProductImplementations;

use App\Enums\MaterialEnums;
use App\Enums\MeasurementUnitEnums;
use App\Enums\ProductEnums;

class HEX_BOLT_Implementation extends ProductBaseImplementation
{
    public function __construct()
    {

    }

    public function productEnum(): ProductEnums
    {
        return ProductEnums::HEX_BOLT;
    }

    public function config(): array
    {
        return [
            "productCategory" => $this->productEnum()->value,
            "isFastener" => true,
            "negativeKeywords" => [
//                "csk",
//                "chemset",
//                "allthread",
//                "chemical anchor",
//                "anchor rod",
//                "threaded rod",
//                "hd bolt",
            ],
            "productRegex" => [
                "bolt",
                "hex",
            ],
            "nominalLengthRegex" => [
                "x+(1?[0-9]?[0-9]|200)",      //x100  (200 or under)
                "x+\s+(1?[0-9]?[0-9]|200)",   //x 100 (200 or under)
                "(\d+)mm",                    //20mm
                "(\d+)\s+mm",                 //20 mm
            ],
            "nominalWidthRegex" => [
                "M+(\d+)", //M16
            ],
            "nominalHeightRegex" => [

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
            "length" => true,
            "width" => true,
            "height" => false,
            "length_placeholder" => "Length (mm)",
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

        return $actualSize." ".$actualGrade.$actualSurface;
    }
}
