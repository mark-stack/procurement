<?php

namespace App\Services\ProductImplementations;

use App\Enums\MaterialEnums;
use App\Enums\MeasurementUnitEnums;
use App\Enums\NestingEnums;
use App\Enums\ProductEnums;
use App\Enums\SupplierGroupEnums;

class SHS_Implementation extends ProductBaseImplementation
{
    public function __construct() {}

    public function productEnum(): ProductEnums
    {
        return ProductEnums::SHS;
    }

    public function config(): array
    {
        /*
         * ProductEnums::SHS has existed since the enum was written, but nothing implemented
         * it, so square hollow section was the one structural family the classifier could not
         * name. Every SHS row in every upload matched no productRegex at all and was dropped
         * by the bare `continue` in CsvService::saveRawMaterialQuoteData() - not reported as
         * not found, not reported as unreadable, just gone.
         *
         * NOTE: there are no SHS rows in the product catalogue yet. Until there are, an SHS
         * descriptor classifies and then reports as "not found", which is the honest outcome -
         * the user is told the line did not import instead of never hearing about it.
         */
        return [
            'productCategory' => $this->productEnum()->value,
            'isFastener' => false,
            'negativeKeywords' => [
                //
            ],
            'productRegex' => [
                "\bSHS",    //100x100x5 SHS
                "(\d+)SHS", //100x100x5SHS
                "\s+SHS",   //100 x 100 x 5 SHS   100 x 100 x 5mm SHS
                "SHS(\d+)", //SHS100*100*5
                "SHS\b",    //SHS 100*100*5
                /*
                 * The hollow structural section SDS2 exports, and the older tube designation.
                 * Both write square and rectangular tube with the same token, so the
                 * backreference is what claims the square case - \1 requires the second face
                 * to repeat the first. The matching negative lookahead in RHS_Implementation
                 * takes the rectangular one.
                 */
                "\bHSS\s?(\d+(?:\.\d+)?)\s*[x*]\s*\\1\s*[x*]",  //HSS6X6X1/4
                "\bTS\s?(\d+(?:\.\d+)?)\s*[x*]\s*\\1\s*[x*]",   //TS6X6X1/4
                "square+\s+hollow+\s+section",
            ],
            'nominalLengthRegex' => [

            ],
            'nominalWidthRegex' => [
                //special formula
            ],
            'nominalHeightRegex' => [
                //special formula
            ],
            'wallRegex' => [
                //special formula
            ],
            'weightRegex' => [

            ],
            'measurementUnit' => MeasurementUnitEnums::MILLIMETERS,
            'defaultMaterial' => MaterialEnums::PLAIN_CARBON_STEEL,
            'supplierGroup' => SupplierGroupEnums::STEEL_MERCHANT,
            "algorithm" => NestingEnums::METERAGE,
        ];
    }

    public function getNominalSizeData(): array
    {
        return [
            'length' => false,
            'width' => true,
            'height' => true,
            'length_placeholder' => '',
            'width_placeholder' => 'Width (mm)',
            'height_placeholder' => 'Height (mm)',
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
        return $nominal_height.'x'.$nominal_width.'x'.$wall.' SHS';
    }

    public function generalProductDefinition(): array
    {
        return [
            'mandatory' => [
                'product_category',
                'material',
                'grade',
                'surface',
                'nominal_units',
                'nominal_width',
                'nominal_height',
                'wall',
            ],
            'exclude' => [
                'kg_per_m',
                'precise_length',
                'precise_height',
                'precise_width',
            ],
            'purchasableVariations' => [
                'nominal_length',
            ],
        ];
    }
}
