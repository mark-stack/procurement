<?php

namespace App\Services\ProductImplementations;

use App\Enums\GradeEnums;
use App\Enums\MaterialEnums;
use App\Enums\MeasurementUnitEnums;
use App\Enums\NestingEnums;
use App\Enums\ProductEnums;
use App\Enums\SupplierGroupEnums;

class ANCHOR_STUD_Implementation extends ProductBaseImplementation
{
    public function __construct() {}

    public function productEnum(): ProductEnums
    {
        return ProductEnums::ANCHOR_STUD;
    }

    public function config(): array
    {
        return [
            'productCategory' => $this->productEnum()->value,
            'isFastener' => true,
            'negativeKeywords' => [
                //                "csk",
                //                "countersink",
                //                "countersunk",
            ],
            'productRegex' => [
                'anchor',
                "chemical+\s+anchor",   //chemical anchor
                "anchor+\s+rod",        //anchor rod
                "hd+\s+bolt",           //hd bolt
            ],
            'nominalLengthRegex' => [
                "x(\d+)\b",     //x100
                "(\d+)mm",      //20mm
                "(\d+)\s+mm",   //20 mm
            ],
            'nominalWidthRegex' => [
                "M+(\d+)", //M16
            ],
            'nominalHeightRegex' => [
                //
            ],
            'wallRegex' => [

            ],
            'weightRegex' => [

            ],
            'measurementUnit' => MeasurementUnitEnums::MILLIMETERS,
            'defaultMaterial' => MaterialEnums::PLAIN_CARBON_STEEL,
            //"defaultGrade" => GradeEnums::NONE, //todo
            'supplierGroup' => SupplierGroupEnums::FASTENERS,
            "algorithm" => NestingEnums::BUNDLE,
        ];
    }

    public function getNominalSizeData(): array
    {
        return [
            'length' => true,
            'width' => true,
            'height' => false,
            'length_placeholder' => 'Length (mm)',
            'width_placeholder' => 'Diameter (mm)',
            'height_placeholder' => '',
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
    ): string {
        //Size
        $actualSize = '';
        if ($nominal_length) {
            $actualSize = 'M'.$nominal_width.'x'.$nominal_length;
        } else {
            $actualSize = 'M'.$nominal_width;
        }

        return $actualSize.' Anchor Stud. '.$actualGrade.' '.$actualSurface;
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

        //todo
        return [
            /*
             * An anchor rod is a diameter and a length - M20 x 500mm. nominal_height used to be
             * mandatory here and no anchor rod in the catalogue has ever carried one, because the
             * product has no third dimension to put in it.
             *
             * It stayed hidden while the seeder inserted the catalogue raw: ProductRules never saw
             * those rows, so a column the data could not supply cost nothing. Validate the same file
             * on an empty database and all seventeen are refused. HEX_BOLT excludes it; so does this.
             */
            'mandatory' => [
                'product_category',
                'material',
                'grade',
                'surface',
                'nominal_units',
                'nominal_width',
                'nominal_length',
            ],
            'exclude' => [
                'wall',
                'kg_per_m',
                'precise_length',
                'precise_height',
                'precise_width',
                'nominal_height',
            ],
            'purchasableVariations' => [

            ],
        ];
    }
}
