<?php

namespace Database\Factories;

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
        $unitTypes = [
            "m","m2","single"
        ];

        $randomUnitType = fake()->randomElement($unitTypes);

        return [
            "description" => fake()->jobTitle(),
            "measurement_unit" => $randomUnitType,
            "material" => "Mild Steel",
//            "purchasable_qty" => $randomUnitType === "single"
//                ? 1
//                : fake()->numberBetween(1,100),
        ];
    }
}
