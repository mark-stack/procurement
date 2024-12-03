<?php

namespace Database\Factories;

use App\Enums\MaterialEnums;
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
        $units = [
            MaterialEnums::STEEL->value,
            MaterialEnums::TIMBER->value,
        ];

        $randomUnits = fake()->randomElement($units);

        return [
            "description" => fake()->jobTitle(),
            "product" => fake()->text(10),
            "material" => fake()->text(10),
            "grade" => fake()->text(10),
            "surface" => fake()->text(10),
            "measurement_unit" => $randomUnits,
            "size" => fake()->text(10),
            "length" => fake()->text(10),
            "width" => fake()->text(10),
            "kg_per_m" => "17.5",
            "baseline_unit_rate" => "$13.54",
        ];
    }
}
