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
    ): string;
}
