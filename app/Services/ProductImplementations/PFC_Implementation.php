<?php

namespace App\Services\ProductImplementations;

use App\Enums\MaterialEnums;
use App\Enums\MeasurementUnitEnums;
use App\Enums\ProductEnums;

class PFC_Implementation extends ProductBaseImplementation
{
    public function __construct()
    {

    }

    public function productEnum(): ProductEnums
    {
        return ProductEnums::PFC;
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
                "PFC",
                "Parallel+\s+Flange+\s+Channel",
                "Parallel+\s+Flanged+\s+Channel",
                "steel+\s+channel",
            ],
            "nominalLengthRegex" => [

            ],
            "nominalWidthRegex" => [

            ],
            "nominalHeightRegex" => [
                "(\d+)+PFC",          //200PFC
                "(\d+)+\s+PFC",       //200 PFC
                "(\d+)+mm+\s+PFC",    //200mm PFC
                "(\d+)+\s+mm+\s+PFC", //200 mm PFC
                "(\d+)+\s+mm+\s+Parallel Flange Channel",    //200 mm Parallel Flange Channel
                "(\d+)+mm+\s+Parallel+\s+Flange+\s+Channel", //200 mm Parallel Flange Channel
                "PFC+\s+(\d+)\b",       //"PFC 200",
                "PFC+(\d+)\b",          //"PFC200",
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
            "width" => false,
            "height" => true,
            "length_placeholder" => "",
            "width_placeholder" => "",
            "height_placeholder" => "Height (nominal)",
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
    ): string
    {
        $actualSize = $nominal_height;

        return $actualSize.$productCategory." ".$actualGrade.$actualSurface;
    }
}
