<?php

namespace App\Services\Interfaces;

/**
 * @deprecated
 */
interface TemplateInterface
{
    public function ownerDomain(): ?string;
    public function type(): string;
    public function source(): string;
    public function confirmDocumentTripleCell(): array;
    public function tableOptions(): array;
    public function isLastDataRowRule1(array $csvArray, int $index, int $descriptionColumnIndex): bool;
    public function isLastDataRowRule2(array $csvArray, int $index, int $descriptionColumnIndex): bool;
    public function isLastDataRowRule3(array $csvArray, int $index, int $descriptionColumnIndex): bool;
    public function shouldSkipRowRule1(array $csvRow, int $descriptionColumnIndex): bool;
    public function shouldSkipRowRule2(array $csvRow, int $descriptionColumnIndex): bool;
    public function getAssemblyReferenceRule1(): string;
    public function getAssemblyReferenceRule2(): string;
}
