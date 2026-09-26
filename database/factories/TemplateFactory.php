<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Template>
 */
class TemplateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        //Business has no factory in this codebase, so callers attach one with
        //->for($business) or $business->templates()->create(...)
        return [
            'name' => fake()->words(2, true),
            'first_description_cell' => 'B7',
            'first_material_cell' => 'C7',
            'first_length_required_cell' => 'D7',
            'first_width_required_cell' => 'E7',
            'first_sub_qty_cell' => 'F7',
            //A 1x1 transparent PNG, enough to satisfy the data-URL rule
            'screenshot' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==',
            'length_width_units' => 'mm',
            'active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['active' => false]);
    }
}
