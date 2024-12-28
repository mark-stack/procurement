<?php

namespace App\Services\ProductImplementations;

use App\Enums\MaterialEnums;
use App\Enums\MeasurementUnitEnums;
use App\Enums\ProductEnums;

class PLATE_Implementation extends ProductBaseImplementation
{
    public function __construct()
    {

    }

    public function productEnum(): ProductEnums
    {
        return ProductEnums::PLATE;
    }

    public function config(): array
    {
        return [
            "productCategory" => $this->productEnum()->value,
            "isFastener" => false,
//            "positiveKeyword" => [
//                //todo
//            ],
            "negativeKeywords" => [
                //
            ],
            "productRegex" => [
                "Plate",                //plate
                "Plates",               //plates
                "(\d+)+PL",             //20PL
                "(\d+)+\s+PL",          //20 PL
                "(\d+)+mm+\s+PL",       //20mm PL
                "(\d+)+mm+\s+plate",    //20mm plate
                "PLT(\d+)\b",           //PLT8
                "Steel+\s+Plate",       //Steel plate
                "Steel+\s+Plates",      //steel plates
                "(\d+)+mm\b.*\b(1200|1220|2400|2440|3000|3100|3200)",    //20mm plus one of 1200|1220|2400|2440|3000|3100|3200
                "(\d+)+\s+mm\b.*\b(1200|1220|2400|2440|3000|3100|3200)", //20 mm plus one of 1200|1220|2400|2440|3000|3100|3200
            ],
            "nominalLengthRegex" => [

            ],
            "nominalWidthRegex" => [
                "(1200|1220|1800|2400|2440|3000|3200|1\.2|1\.8|1\.22|2\.4|2\.44|3\.0|3\.2)", //Find common plate widths in M or MM
            ],
            "nominalHeightRegex" => [
                "\b(0|[1-9][0-9]?|1[0-4][0-9]|150) ?PL", //16PL or 16 PL
                "\b(0|[1-9][0-9]?|1[0-4][0-9]|150) ?mm", //16mm or 16 mm
                "PLT(\d+)\*",                            //PLT10*234
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
            "height_placeholder" => "Thickness (mm)",
        ];
    }

    public function formatLabel(string $productCategory, ?float $nominal_length, ?float $nominal_width, ?float $nominal_height, ?string $actualGrade, ?string $actualSurface): string
    {
        $actualSize = $nominal_height."PL";
        return $actualSize." ".$actualGrade.$actualSurface;
    }
}
