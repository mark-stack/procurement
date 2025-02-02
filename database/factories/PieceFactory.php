<?php

namespace Database\Factories;

use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Piece>
 */
class PieceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id'
            'raw_material_quote_id'
            'order_id'
            'batch_id'
            'product_category'
            'material'
            'grade'
            'surface'
            'nominal_units'
            'nesting_algo'
            'nominal_length'
            'precise_length'
            'nominal_width'
            'precise_width'
            'nominal_height'
            'precise_height'
            'wall'
            'kg_per_m'
            'actual_length'
            'actual_width'
            'actual_qty'
        ];
    }
}
