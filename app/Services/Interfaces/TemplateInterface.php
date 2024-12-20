<?php

namespace App\Services\Interfaces;

interface TemplateInterface
{
    public function ownerDomain(): ?string;
    public function type(): string;
    public function source(): string;
    public function confirmDocumentTripleCell(): array;

    public function firstDataRowIndex(): int;
    public function lastDataRowIndex(): int;
    public function skipRowRule(): void;
    public function assemblyReferenceRule(): void;
    public function descriptionColumnIndex(): int;
    public function materialColumnIndex(): ?int;
    public function lengthColumnIndex(): int;
    public function widthColumnIndex(): ?int;
    public function subQtyColumnIndex(): int;
    public function unitRateColumnIndex(): ?int;
}
