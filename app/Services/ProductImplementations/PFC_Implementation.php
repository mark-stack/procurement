<?php

namespace App\Services\ProductImplementations;

use App\Enums\GradeEnums;
use App\Enums\MaterialEnums;
use App\Enums\MeasurementUnitEnums;
use App\Enums\NestingEnums;
use App\Enums\ProductEnums;
use App\Enums\SupplierGroupEnums;

class PFC_Implementation extends ProductBaseImplementation
{
    public function __construct() {}

    public function productEnum(): ProductEnums
    {
        return ProductEnums::PFC;
    }

    public function config(): array
    {
        return [
            'productCategory' => $this->productEnum()->value,
            'isFastener' => false,
            'negativeKeywords' => [
                //
            ],
            'productRegex' => [
                'PFC',
                "Parallel+\s+Flange+\s+Channel",
                "Parallel+\s+Flanged+\s+Channel",
                "steel+\s+channel",
                /*
                 * European channel, which Advance Steel and Tekla write for a DIN section.
                 * Unambiguous tokens, unlike the American "C150" - see the note on the AISC
                 * patterns below.
                 */
                "\bUPN\s?(\d+)",    //UPN200
                "\bUPE\s?(\d+)",    //UPE200
                /*
                 * American channel, which is what SDS2 exports. The mass suffix is what makes
                 * these safe to read: a bare "C150" is just as likely to be a column mark on
                 * a drawing as it is a 15-inch channel, so only the dimensioned form counts.
                 */
                "\bC\d+(\.\d+)?\s?[xX*]\s?\d+(\.\d+)?",     //C15X33.9
                "\bMC\d+(\.\d+)?\s?[xX*]\s?\d+(\.\d+)?",    //MC18X42.7
            ],
            'nominalLengthRegex' => [

            ],
            'nominalWidthRegex' => [

            ],
            /*
             * Leading-token forms FIRST, trailing-token forms last, because the last pattern
             * to match wins - see DataClassificationService::findNumberByRegexPatterns().
             *
             * That order is the whole point. "PFC 200" has to be readable, but the pattern
             * that reads it cannot tell 200 from a length, so on "150PFC 9000" it matched
             * "PFC 9000" and - being last - overwrote the 150 the trailing pattern had
             * already read correctly. A 9000mm-deep channel matched nothing in the
             * catalogue. Reading the trailing form last settles it the other way round.
             *
             * The American forms are deliberately absent here. Their leading number is a
             * depth in INCHES, and nothing converts it, so a C15X33.9 classifies as a
             * channel with no depth rather than as a 15mm one.
             */
            'nominalHeightRegex' => [
                //No trailing \b - "PFC300x90" runs straight into its flange width
                "PFC+\s*(50|[5-9][0-9]|[1-9][0-9]{2,})",     //"PFC 200", "PFC200" (50 or above)
                "\bUPN\s?(\d+)",                             //UPN200
                "\bUPE\s?(\d+)",                             //UPE200
                //The metric American channel. Three digits or more, because two is the inch
                //spelling of the same designation and ImperialSectionReader converts that
                "\bM?C([1-9][0-9]{2,})\s?[x*]",              //C310X31, MC460X63
                "(\d+)+PFC",          //200PFC
                "(\d+)+\s+PFC",       //200 PFC
                "(\d+)+mm+\s+PFC",    //200mm PFC
                "(\d+)+\s+mm+\s+PFC", //200 mm PFC
                "(\d+)+\s+mm+\s+Parallel Flange Channel",    //200 mm Parallel Flange Channel
                "(\d+)+mm+\s+Parallel+\s+Flange+\s+Channel", //200 mm Parallel Flange Channel
            ],
            'wallRegex' => [

            ],
            'weightRegex' => [

            ],
            'measurementUnit' => MeasurementUnitEnums::MILLIMETERS,
            'defaultMaterial' => MaterialEnums::PLAIN_CARBON_STEEL,
            //"defaultGrade" => GradeEnums::GR300,
            'supplierGroup' => SupplierGroupEnums::STEEL_MERCHANT,
            "algorithm" => NestingEnums::METERAGE,
        ];
    }

    public function getNominalSizeData(): array
    {
        return [
            'length' => false,
            'width' => false,
            'height' => true,
            'length_placeholder' => '',
            'width_placeholder' => '',
            'height_placeholder' => 'Height (nominal)',
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
        /*
         * 1) If plain carbon steel, just display "200PFC"
         * 2) Any other grades, display "200PFC SS316"
         */
        $surface = $actualSurface ? (' '.$actualSurface) : '';

        $display = '';
        if ($material === MaterialEnums::PLAIN_CARBON_STEEL->value) {
            $display = $nominal_height.$productCategory.$surface;
        } else {
            $display = $nominal_height.$productCategory.' '.$material.$surface;
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
            'mandatory' => [
                'product_category',
                'material',
                'grade',
                'surface',
                'nominal_units',
                'nominal_height',
            ],
            'exclude' => [
                'nominal_width',
                'precise_length',
                'precise_height',
                'precise_width',
                'wall',
                'kg_per_m',
            ],
            'purchasableVariations' => [
                'nominal_length',
            ],
        ];
    }
}
