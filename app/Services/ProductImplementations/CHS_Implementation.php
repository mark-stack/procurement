<?php

namespace App\Services\ProductImplementations;

use App\Enums\GradeEnums;
use App\Enums\MaterialEnums;
use App\Enums\MeasurementUnitEnums;
use App\Enums\ProductEnums;
use App\Enums\SupplierGroupEnums;

class CHS_Implementation extends ProductBaseImplementation
{
    public function __construct()
    {

    }

    public function productEnum(): ProductEnums
    {
        return ProductEnums::CHS;
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
                "(\d+(\.\d+)?)CHS",         //38CHS or 37.6CHS
                "CHS+\d+(\.\d+)?",          //CHS38 or CSH37.6
                "(\d+(\.\d+)?)+\s+CHS",     //38 CHS or 37.6 CHS
                "CHS+\s+(\d+(\.\d+)?)",     //CHS 38 or CHS 37.6
                "(\d+(\.\d+)?)nb",          //38nb  or 37.6nb
                "(\d+(\.\d+)?)n.b",         //38n.b or 37.6n.b
                "(\d+(\.\d+)?)+\s+n.b",     //38 n.b or 37.6 n.b
            ],
            "nominalLengthRegex" => [

            ],
            "nominalWidthRegex" => [
                "(\d+(\.\d+)?)CHS",         //38CHS or 37.6CHS
                "CHS+\d+(\.\d+)?",          //CHS38 or CSH37.6
                "(\d+(\.\d+)?)+\s+CHS",     //38 CHS or 37.6 CHS
                "CHS+\s+(\d+(\.\d+)?)",     //CHS 38 or CHS 37.6
                "(\d+(\.\d+)?)nb",          //38nb  or 37.6nb
                "(\d+(\.\d+)?)n.b",         //38n.b or 37.6n.b
                "(\d+(\.\d+)?)+\s+n.b",     //38 n.b or 37.6 n.b
            ],
            "nominalHeightRegex" => [

            ],
            "wallRegex" => [
                "\*(\d+(\.\d+)?)",  //*6.0     CHS193.7*6.0
                "(\d+(\.\d+)?)THK", //6.0THK   CHS193 x 6.0THK
                "x(\d+(\.\d+)?)",  //x6.4     CHS 200nb (Ø219.1x6.4) 12m
            ],
            "weightRegex" => [

            ],
            "measurementUnit" => MeasurementUnitEnums::MILLIMETERS,
            "defaultMaterial" => MaterialEnums::PLAIN_CARBON_STEEL,
            //"defaultGrade" => GradeEnums::NONE, //todo
            "supplierGroup" => SupplierGroupEnums::STEEL_MERCHANT, //todo
        ];
    }


    public function getNominalSizeData(): array
    {
        return [
            "length" => false,
            "width" => true,
            "height" => false,
            "length_placeholder" => "",
            "width_placeholder" => "Diameter (nominal)",
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
        /*
         * 20nb (Ø33.7x3.2)
         */

        $nominalDiameter = $nominal_width;
        $actualDiameter = $precise_width;
        $blackSurface = ["NONE",""," "];
        $surface = in_array($actualSurface,$blackSurface) ? ' BLACK' : $actualSurface;

        return "CHS ".$nominalDiameter."nb (Ø".$actualDiameter."x".$wall.") ".$actualGrade.$surface;
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
                "nominal_width",
                "precise_width",
                "wall",
            ],
            "exclude" => [
                'nominal_height',
                "precise_length",
                "precise_height",
                "kg_per_m"
            ],
            "purchasableVariations" => [
                "nominal_length",
            ],
        ];
    }
}
