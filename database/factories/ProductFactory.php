<?php

namespace Database\Factories;

use App\Enums\GradeEnums;
use App\Enums\MaterialEnums;
use App\Enums\MeasurementUnitEnums;
use App\Enums\NestingEnums;
use App\Enums\ProductEnums;
use App\Enums\SurfaceEnums;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Product>
 */
class ProductFactory extends Factory
{
    /**
     * One platform PFC, the catalogue's most ordinary kind of row.
     *
     * Every value here is a real one. This used to name five columns the products table has never
     * had - product, size, length, width and domain - and random text in material, grade and
     * surface, so anything built on it was a product no piece spec could ever match.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'description' => '200PFC 9m',
            'product_category' => ProductEnums::PFC->value,
            'material' => MaterialEnums::PLAIN_CARBON_STEEL->value,
            'grade' => GradeEnums::GR300->value,
            'surface' => SurfaceEnums::NONE->value,

            //Both follow from the product category rather than being chosen per row
            'nesting_algo' => NestingEnums::METERAGE->value,
            'nominal_units' => MeasurementUnitEnums::MILLIMETERS->value,

            'certificates' => true,
            'nominal_length' => '9000',
            'nominal_height' => '200',
            'kg_per_m' => 25.1,
            'pack_size_1' => '1',

            //Platform catalogue, available to every business
            'business_id' => null,
            'deprecated' => false,
        ];
    }

    /**
     * Retired from the catalogue: no longer offered for new work, while everything already built on
     * it still resolves to it.
     */
    public function deprecated(): static
    {
        return $this->state(fn () => ['deprecated' => true]);
    }
}
