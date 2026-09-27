<?php

namespace Database\Factories;

use App\Models\Template;
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
        /*
         * Read off the config rather than hard-coded, so the factory cannot name a
         * detection entry that does not exist - which is exactly what the source and
         * config_label columns are there to prevent.
         */
        $entry = Template::detectionOptions()[0];

        //Business has no factory in this codebase, so callers attach one with
        //->for($business) or $business->templates()->create(...)
        return [
            //Unique per business, so a factory run of several rows does not collide
            'name' => fake()->unique()->words(2, true),
            'source' => $entry['source'],
            'config_label' => $entry['config_label'],
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
