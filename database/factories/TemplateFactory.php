<?php

namespace Database\Factories;

use App\Enums\TemplateEnums;
use App\Enums\TemplateSourceEnums;
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
     * The default is a template that could really detect something: heading labels, an anchor
     * above the data, and columns measured from it. A factory row that cannot detect would pass
     * every test about recording templates while proving nothing about the thing the table is
     * now for - and StoreTemplateRequest refuses records like that anyway.
     *
     * The labels are unique per row so two factory templates cannot match the same heading row
     * and import it twice.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $marker = fake()->unique()->words(2, true);

        //Business has no factory in this codebase, so callers attach one with
        //->for($business) or $business->templates()->create(...)
        return [
            //Unique per business, so a factory run of several rows does not collide
            'name' => $marker,
            'source' => TemplateSourceEnums::TEKLA->value,
            'type' => TemplateEnums::CAD_BILL_OF_MATERIALS->value,
            'heading_cell' => 'A6',
            'expected_heading_labels' => [$marker, 'Qty', 'Length'],
            'first_description_cell' => 'B7',
            'first_material_cell' => 'C7',
            'first_grade_cell' => null,
            'first_surface_cell' => null,
            'first_length_required_cell' => 'D7',
            'first_width_required_cell' => 'E7',
            'first_sub_qty_cell' => 'F7',
            'skip_or_finish_check_cell' => 'B7',
            'should_skip_row' => null,
            'is_last_data_row' => null,
            'compound_description_prefix' => null,
            'compound_description_suffix' => null,
            'compound_description_cells' => null,
            'assembly_mark_rule' => 'NONE',
            'assembly_mark_cell' => null,
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

    /**
     * A row as it was recorded before templates drove detection: cell references and no heading
     * row, so there is nothing to find it by. These exist in the table and the screen has to say
     * what is wrong with them.
     */
    public function undetectable(): static
    {
        return $this->state(fn (array $attributes) => [
            'heading_cell' => null,
            'expected_heading_labels' => null,
        ]);
    }
}
