<?php

namespace App\Services\TemplateImplementations;

use App\Enums\TemplateEnums;
use App\Enums\TemplateSourceEnums;
use App\Services\Interfaces\TemplateInterface;

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
