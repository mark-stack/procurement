<?php

namespace App\Services\ProductImplementations;

use App\Enums\GradeEnums;
use App\Enums\MaterialEnums;
use App\Enums\MeasurementUnitEnums;
use App\Enums\ProductEnums;
use App\Enums\SupplierGroupEnums;

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
            "negativeKeywords" => [
                //
            ],
            "productRegex" => [
                "Plate",                //plate
                "Plates",               //plates
                "\b(\d+)+PL",             //20PL
                "\b(\d+)+\s+PL",          //20 PL
                "\b(\d+)+mm+\s+PL",       //20mm PL
                "\b(\d+)+mm+\s+plate",    //20mm plate
                "\bPLT(\d+)\b",           //PLT8
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
            "wallRegex" => [

            ],
            "weightRegex" => [

            ],
            "measurementUnit" => MeasurementUnitEnums::MILLIMETERS,
            "defaultMaterial" => MaterialEnums::PLAIN_CARBON_STEEL,
            //"defaultGrade" => GradeEnums::NONE, //todo
            "supplierGroup" => SupplierGroupEnums::STEEL_MERCHANT,
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
        $actualSize = $nominal_height."PL";
        return $actualSize." ".$actualGrade;
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
                "precise_length",
                "precise_height",
                "precise_width",
                "wall",
                "kg_per_m",
            ],
            "purchasableVariations" => [
                "nominal_width",
                "nominal_length",
            ],
        ];
    }
}
