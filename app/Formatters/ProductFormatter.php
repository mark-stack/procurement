<?php

namespace App\Formatters;

use App\Models\Business;
use App\Models\RawMaterialQuote;
use App\Services\ProductService;

class ProductFormatter
{
    public function requiresCustomForm(RawMaterialQuote $rawMaterialQuote): array
    {
        $productService = new ProductService();

        return [
            'selected' => [
                'product_category' => null,
                'material' => null,
                'grade' => null,
                'nominal_length' => null,
                'nominal_width' => null,
                'nominal_height' => null,
                'nesting_algo' => null,
                'purchasable_length_1' => null,
                'purchasable_length_2' => null,
                'purchasable_length_3' => null,
                'purchasable_width_1' => null,
                'purchasable_width_2' => null,
                'purchasable_width_3' => null,
                'suppliers' => [],
            ],
            'selected_other' => [
                'product_category' => null,
                'material' => null,
                'grade' => null,
                'surface' => null,
                'suppliers' => [],
            ],
            'data' => $rawMaterialQuote,
            'nominalSizeData' => $productService->getNominalSizeData(),
        ];
    }
}
