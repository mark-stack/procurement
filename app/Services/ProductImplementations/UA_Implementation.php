<?php

namespace App\Services\ProductImplementations;

use App\Enums\MaterialEnums;
use App\Enums\MeasurementUnitEnums;
use App\Enums\ProductEnums;

class UA_Implementation extends ProductBaseImplementation
{
    public function __construct()
    {

    }

    public function productEnum(): ProductEnums
    {
        return ProductEnums::UA;
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
                "UA(\d+)",         //"UA150*100*10"
                "x(\d+)+\s+UA",    //"150x100x10 UA"
                "x(\d+)UA",        //"150x100x10 UA"
                "\b(\d+)+\s+UA",   //"150 x 100 x 10 UA"
                "\b(\d+)mm+\s+UA", //"150 x 100mm UA"
            ],
            "nominalLengthRegex" => [

            ],
            "nominalWidthRegex" => [
                //uses special rule
            ],
            "nominalHeightRegex" => [
                //uses special rule
            ],
            "wallRegex" => [
                //uses special rule
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
            "height_placeholder" => "Height (mm)",
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
        return $nominal_height."x".$nominal_width."x".$wall." UA";
    }
}
