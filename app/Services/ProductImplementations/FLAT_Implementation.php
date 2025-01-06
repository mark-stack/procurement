<?php

namespace App\Services\ProductImplementations;

use App\Enums\MaterialEnums;
use App\Enums\MeasurementUnitEnums;
use App\Enums\ProductEnums;

class FLAT_Implementation extends ProductBaseImplementation
{
    public function __construct()
    {

    }

    public function productEnum(): ProductEnums
    {
        return ProductEnums::FLAT;
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
                "FL(\d+)",                      //"FL8*75"
                "(\d+)x(\d+)+\sflatbar",        //"100x10 flatbar"
                "(\d+)x(\d+)mm+\sflat+\sbar",   //"100x10mm flat bar"
                "(\d+)x(\d+)mm+\sflatbar",      //"100x10mm flatbar"
                "(\d+)FL\b",                    //"10FL x 75mm"
                "(\d+)FLx",                     //"10FLx75"
                "(\d+)x(\d+)FL",                //"10x75FL"
                "(\d+)x(\d+)+\sFL",             //"10x75 FL"
                "(\d+)mm+\s+flatbar",           //"10mm flatbar x 75mm"
                "FLAT(\d+)x(\d+)",              //"FLAT10x75"
                "FLAT+\s(\d+)x(\d+)",           //"FLAT 10x75"
            ],
            "nominalLengthRegex" => [

            ],
            "nominalWidthRegex" => [
                "\*(\d+)",      //*75     "FL8*75"
                "x(\d+)",       //x75     "FL8x75"
                "x(\d+)mm",     //x75mm   "FL8x75mm"
                "x(\d+)+\smm",  //x75 mm  "FL8x75 mm"
                "x+\s(\d+)mm",  //x 75mm  "FL8 x 75mm"
            ],
            "nominalHeightRegex" => [
                "FL(\d+)",              //FL8               "FL8*75"
                "(\d+)FL\b",            //10FL              "10FL x 75mm"
                "(\d+)mm+\s+flatbar",   //10mm flatbar      "10mm flatbar x 75mm"
                "(\d+)FLx",             //10FLx             "10FLx75"
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
            "length" => false,
            "width" => true,
            "height" => true,
            "length_placeholder" => "",
            "width_placeholder" => "Width (mm)",
            "height_placeholder" => "Thickness (mm)",
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
        return $nominal_width."x".$nominal_height."mm FLAT BAR";
    }
}
