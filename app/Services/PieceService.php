<?php

namespace App\Services;

use App\Models\Piece;
use App\Models\Product;
use App\Models\RawMaterialQuote;

class PieceService
{
    public function createPieceFromProductSpec(array $productSpec, RawMaterialQuote $rawMaterialQuote, string $algo): Piece
    {
        return Piece::create([
            //RawMaterialQuote fields
            'project_id' => $rawMaterialQuote->project_id,
            'raw_material_quote_id' => $rawMaterialQuote->id,
            'actual_length' => $rawMaterialQuote->length_required,
            'actual_width' => $rawMaterialQuote->width_required,
            'actual_qty' => $rawMaterialQuote->sub_qty,

            //Other fields
            'nesting_algo' => $algo,

            //Product spec fields
            'product_category' => $productSpec['product_category'] ?? null,
            'material' => $productSpec['material'] ?? null,
            'grade' => $productSpec['grade'] ?? null,
            'surface' => $productSpec['surface'] ?? null,
            'nominal_units' => $productSpec['nominal_units'] ?? null,
            'nominal_length' => $productSpec['nominal_length'] ?? null,
            'precise_length' => $productSpec['precise_length'] ?? null,
            'nominal_width' => $productSpec['nominal_width'] ?? null,
            'precise_width' => $productSpec['precise_width'] ?? null,
            'nominal_height' => $productSpec['nominal_height'] ?? null,
            'precise_height' => $productSpec['precise_height'] ?? null,
            'wall' => $productSpec['wall'] ?? null,
            'kg_per_m' => $productSpec['kg_per_m'] ?? null,
        ]);
    }
}
