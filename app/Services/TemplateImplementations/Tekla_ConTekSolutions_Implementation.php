<?php

namespace App\Services\TemplateImplementations;

use App\Enums\TemplateEnums;
use App\Enums\TemplateSourceEnums;
use App\Services\Interfaces\TemplateInterface;

/**
 * @deprecated
 */
class Tekla_ConTekSolutions_Implementation implements TemplateInterface
{
    public function __construct()
    {
        //
    }

    public function ownerDomain(): ?string
    {
        return null; //For everybody
    }

    public function type(): string
    {
        return TemplateEnums::CAD_BILL_OF_MATERIALS->value;
    }

    public function source(): string
    {
        return TemplateSourceEnums::TEKLA->value;
    }

    public function confirmDocumentTripleCell(): array
    {
        return [
            [
                "spreadsheet_coordinate" => "a2",
                "text" => "project number:",
            ],
            [
                "spreadsheet_coordinate" => "o6",
                "text" => "finish",
            ],
            [
                "spreadsheet_coordinate" => "a4",
                "text" => "date",
            ],
        ];
    }

    public function tableOptions(): array
    {
        //todo validate this structure for completeness
        return [
            [
                "ExpectedHeadingLabels" => ["Mark","Qty","Profile","Name","Finish","Length (mm)","Unit Area (m2)","Unit Weight (kg)"],
                "OffsetFromHeaderToFirstDataRow" => 1,
                "ShouldSkipRow" => "shouldSkipRowRule1",
                "isLastDataRow" => "isLastDataRowRule1",
                "DescriptionColumnNumber" => 4,
                "MaterialColumnNumber" => null,
                "GradeColumnNumber" => null,
                "SurfaceColumnNumber" => 15, //"O"
                "LengthColumnNumber" => 19, //"S"
                "WidthColumnNumber" => null,
                "SubQtyColumnNumber" => 2, //"B"
                "UnitRateColumnNumber" => null,
                "nominalUnits" => "mm",
            ],
            [
                "ExpectedHeadingLabels" => ["Profile","Grade","Part Mark","Qty","Length[mm]","Unit Area (m2)","Total Area (m2)","Unit Weight (kg)","Total Weight (kg)"], //todo
                "OffsetFromHeaderToFirstDataRow" => 1,
                "ShouldSkipRow" => "shouldSkipRowRule2",
                "isLastDataRow" => "isLastDataRowRule2",
                "DescriptionColumnNumber" => 1,
                "MaterialColumnNumber" => null,
                "GradeColumnNumber" => 3,
                "SurfaceColumnNumber" => null,
                "LengthColumnNumber" => 11,
                "WidthColumnNumber" => null,
                "SubQtyColumnNumber" => 9,
                "UnitRateColumnNumber" => null,
                "nominalUnits" => "mm",
            ],
            [
                "ExpectedHeadingLabels" => ["Bolt Dia","Bolt Grade","Length(mm)","Qty","Comments"],
                "OffsetFromHeaderToFirstDataRow" => 1,
                "ShouldSkipRow" => "shouldSkipRowRule1",
                "isLastDataRow" => "isLastDataRowRule3",
                "DescriptionColumnNumber" => 999, //todo
                "MaterialColumnNumber" => null,
                "GradeColumnNumber" => 5, //"E"
                "SurfaceColumnNumber" => null,
                "LengthColumnNumber" => 13, //"M"
                "WidthColumnNumber" => 1, //"A"
                "SubQtyColumnNumber" => 16, //"P"
                "UnitRateColumnNumber" => null,
                "nominalUnits" => "mm",
            ],

            //Bolt Dia Profile Name Length(mm) Qty Finish

//            [
//                "ExpectedHeadingLabels" => [], //todo
//                "OffsetFromHeaderToFirstDataRow" => 999, //todo
//                "ShouldSkipRow" => "shouldSkipRowRule1", //todo
//                "isLastDataRow" => "isLastDataRowRule1", //todo
//                "DescriptionColumnNumber" => 999, //todo
//                "MaterialColumnNumber" => 999, //todo
//                "GradeColumnNumber" => null,
//                "SurfaceColumnNumber" => null,
//                "LengthColumnNumber" => 999, //todo
//                "WidthColumnNumber" => 999, //todo
//                "SubQtyColumnNumber" => 999, //todo
//                "UnitRateColumnNumber" => 999, //todo
//                "nominalUnits" => "999", //mm/m todo
//            ],
            //todo add more table options
        ];
    }

    public function isLastDataRowRule1(array $csvArray, int $index, int $descriptionColumnIndex): bool
    {
        /**
         * 2 consecutive blank 'description' cells
         */
        $thisDescription = $csvArray[$index][$descriptionColumnIndex];
        $thisDescriptionCellBlank = $thisDescription === "" || $thisDescription === null;

        //Next row exists
        $nextDescriptionCellBlank = false;
        if(isset($csvArray[$index + 1])){
            $nextDescription = $csvArray[$index + 1][$descriptionColumnIndex];
            $nextDescriptionCellBlank = $nextDescription === "" || $nextDescription === null;
        }

        return $thisDescriptionCellBlank && $nextDescriptionCellBlank;
    }

    public function isLastDataRowRule2(array $csvArray, int $index, int $descriptionColumnIndex): bool
    {
        /**
         * Description cell = "Total"
         */
        return strtoupper($descriptionColumnIndex) === strtoupper("Total");
    }

    public function isLastDataRowRule3(array $csvArray, int $index, int $descriptionColumnIndex): bool
    {
        /**
         * Description cell = "Bolt Dia"
         */
        return strtoupper($descriptionColumnIndex) === strtoupper("Bolt Dia");
    }

    public function shouldSkipRowRule1(array $csvRow, int $descriptionColumnIndex): bool
    {
        return false;
    }

    public function shouldSkipRowRule2(array $csvRow, int $descriptionColumnIndex): bool
    {
        //Description column = "Subtotal"
        return strtoupper($descriptionColumnIndex) === strtoupper("Subtotal");
    }

    public function getAssemblyReferenceRule1(): string
    {
        //not used
        return ""; //todo placeholder
    }

    public function getAssemblyReferenceRule2(): string
    {
        //not used
        return ""; //todo placeholder
    }
}
