<?php

namespace App\Services\TemplateImplementations;

use App\Enums\TemplateEnums;
use App\Enums\TemplateSourceEnums;
use App\Services\Interfaces\TemplateInterface;

/**
 * @deprecated
 */
class MarksExampleProjectQuoteImplementation implements TemplateInterface
{
    public function __construct()
    {
        //
    }

    public function ownerDomain(): ?string
    {
        return "gmail.com";
    }

    public function type(): string
    {
        return TemplateEnums::PROJECT_QUOTE->value;
    }

    public function source(): string
    {
        return TemplateSourceEnums::PROJECT_MANAGER->value;
    }

    public function confirmDocumentTripleCell(): array
    {
        return [
            [
                "spreadsheet_coordinate" => "j24",
                "text" => "Income",
            ],
            [
                "spreadsheet_coordinate" => "b24",
                "text" => "Expenses",
            ],
            [
                "spreadsheet_coordinate" => "j21",
                "text" => "Planned",
            ],
        ];
    }

    public function tableOptions(): array
    {
        //todo validate this structure for completeness
        return [
            [
                "ExpectedHeadingLabels" => ["Length","Width","SubQty","Rate","Total"],
                "OffsetFromHeaderToFirstDataRow" => 2,
                "ShouldSkipRow" => "shouldSkipRowRule1",
                "isLastDataRow" => "isLastDataRowRule1",
                "DescriptionColumnNumber" => 2,
                "MaterialColumnNumber" => null,
                "GradeColumnNumber" => null,
                "SurfaceColumnNumber" => null,
                "LengthColumnNumber" => 4,
                "WidthColumnNumber" => null,
                "SubQtyColumnNumber" => 6,
                "UnitRateColumnNumber" => 7,
                "nominalUnits" => "m",
            ],
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
        //not used
        return true; //todo placeholder
    }

    public function isLastDataRowRule3(array $csvArray, int $index, int $descriptionColumnIndex): bool
    {
        //not used
        return true; //todo placeholder
    }

    public function shouldSkipRowRule1(array $csvRow, int $descriptionColumnIndex): bool
    {
        //If description column is blank
        return $csvRow[$descriptionColumnIndex] === "" || $csvRow[$descriptionColumnIndex] === null;
    }

    public function shouldSkipRowRule2(array $csvRow, int $descriptionColumnIndex): bool
    {
        return false;
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
