<?php

namespace Database\Factories;

use App\Enums\MeasurementUnitEnums;
use App\Enums\NestingEnums;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Product>
 */
class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'description' => fake()->jobTitle(),
            'product' => fake()->text(10),
            'material' => fake()->text(10),
            'grade' => fake()->text(10),
            'surface' => fake()->text(10),
            'nominal_units' => MeasurementUnitEnums::METERS->value,
            'nesting_algo' => NestingEnums::METERAGE->value,
            'size' => fake()->text(10),
            'length' => fake()->text(10),
            'width' => fake()->text(10),
            'kg_per_m' => '17.5',
            'baseline_unit_rate' => '$13.54',
            'domain' => null, //platform created
        ];
    }
}
