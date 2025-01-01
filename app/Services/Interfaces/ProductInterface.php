<?php

namespace App\Services\Interfaces;

use App\Enums\ProductEnums;

interface ProductInterface
{
    /**
     * Shared methods
     */
    public function validateConfig(): bool;

    /**
     * Specific methods
     */
    public function productEnum(): ProductEnums;

    public function config(): array;

    public function getNominalSizeData(): array;

    public function formatLabel(
        string $productCategory,
        ?float $nominal_length,
        ?float $actual_length,
        ?float $nominal_width,
        ?float $actual_width,
        ?float $nominal_height,
        ?float $actual_height,
        ?string $actualGrade,
        ?string $actualSurface
    ): string;
}
