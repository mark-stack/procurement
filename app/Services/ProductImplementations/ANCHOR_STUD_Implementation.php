<?php

namespace App\Services\ProductImplementations;

use App\Enums\MaterialEnums;
use App\Enums\MeasurementUnitEnums;
use App\Enums\ProductEnums;

class ANCHOR_STUD_Implementation extends ProductBaseImplementation
{
    public function __construct()
    {

    }

    public function productEnum(): ProductEnums
    {
        return ProductEnums::ANCHOR_STUD;
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
//                "csk",
//                "countersink",
//                "countersunk",
            ],
            "productRegex" => [
                "anchor",
                "chemical+\s+anchor",   //chemical anchor
                "anchor+\s+rod",        //anchor rod
                "hd+\s+bolt",           //hd bolt
            ],
            "nominalLengthRegex" => [
                "(\d+)mm",      //20mm
                "(\d+)\s+mm",   //20 mm
            ],
            "nominalWidthRegex" => [
                "M+(\d+)", //M16
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

        return $actualSize." Anchor Stud. ".$actualGrade." ".$actualSurface;
    }
}
