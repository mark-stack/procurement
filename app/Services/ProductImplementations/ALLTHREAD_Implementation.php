<?php

namespace App\Services\ProductImplementations;

use App\Enums\MaterialEnums;
use App\Enums\MeasurementUnitEnums;
use App\Enums\ProductEnums;

class ALLTHREAD_Implementation extends ProductBaseImplementation
{
    public function __construct()
    {

    }

    public function productEnum(): ProductEnums
    {
        return ProductEnums::ALLTHREAD;
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
                "chemset",              //chemset
                "allthread",            //allthread
                "threaded+\s+rod",      //thread rod
            ],
            "nominalLengthRegex" => [
                "x+(20[1-9]|2[1-9][0-9]|[3-9][0-9]{2,}|\d{4,})",      //x100     (201+)
                "x+\s+(20[1-9]|2[1-9][0-9]|[3-9][0-9]{2,}|\d{4,})",   //x 100    (201+)
                "(20[1-9]|2[1-9][0-9]|[3-9][0-9]{2,}|\d{4,})+\s+mm",  //1000 mm  (201+)
                "(20[1-9]|2[1-9][0-9]|[3-9][0-9]{2,}|\d{4,})+mm",     //1000mm   (201+)
            ],
            "nominalWidthRegex" => [
                "M+(\d+)", //M16
            ],
            "nominalHeightRegex" => [

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

        return "x".$actualSize." ".$actualGrade.$actualSurface." Allthread";
    }
}
