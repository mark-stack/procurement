<?php

namespace App\Services\TemplateImplementations;

use App\Enums\TemplateEnums;
use App\Enums\TemplateSourceEnums;
use App\Services\Interfaces\TemplateInterface;

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
                "spreadsheet_coordinate" => "a1",
                "text" => "project number:",
            ],
            [
                "spreadsheet_coordinate" => "e4",
                "text" => "finish",
            ],
            [
                "spreadsheet_coordinate" => "a3",
                "text" => "date",
            ],
        ];
    }

    public function firstDataRowIndex(): int
    {

    }

    public function lastDataRowIndex(): int
    {

    }

    public function skipRowRule(): void
    {

    }

    public function assemblyReferenceRule(): void
    {

    }

    public function descriptionColumnIndex(): int
    {

    }

    public function materialColumnIndex(): ?int
    {

    }

    public function lengthColumnIndex(): int
    {

    }

    public function widthColumnIndex(): ?int
    {

    }

    public function subQtyColumnIndex(): int
    {

    }

    public function unitRateColumnIndex(): ?int
    {

    }
}
