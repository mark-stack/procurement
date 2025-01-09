<?php

namespace App\Services\ProductImplementations;

use App\Enums\GradeEnums;
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
                "PFC+\s(50|[5-9][0-9]|[1-9][0-9]{2,})\b",       //"PFC 200" (50 or above)
                "PFC(50|[5-9][0-9]|[1-9][0-9]{2,})",          //"PFC200",
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
        ?string $material,
    ): string
    {
        /*
         * 1) If plain carbon steel, just display "200PFC"
         * 2) Any other grades, display "200PFC SS316"
         */
        $surface = $actualSurface ? (" ".$actualSurface) : '';

        $display = "";
        if($material === MaterialEnums::PLAIN_CARBON_STEEL->value){
            $display = $nominal_height.$productCategory.$surface;
        }
        else{
            $display = $nominal_height.$productCategory." ".$material.$surface;
        }

        return $display;
    }

    public function generalProductDefinition(): array
    {
//        'product_category',
//        'material',
//        'grade',
//        'surface',
//        'nominal_units',
//        "nominal_length",
//        "precise_length",
//        "nominal_width",
//        "precise_width",
//        'nominal_height',
//        "precise_height",
//        "wall",
//        "kg_per_m"

        return [
            "mandatory" => [
                'product_category',
                'material',
                'grade',
                'surface',
                'nominal_units',
                'nominal_height',
            ],
            "exclude" => [
                "nominal_width",
                "precise_length",
                "precise_height",
                "precise_width",
                "wall",
                "kg_per_m",
            ],
            "purchasableVariations" => [
                "nominal_length",
            ],
        ];
    }
}
