<?php

namespace Database\Factories;

use App\Enums\GradeEnums;
use App\Enums\MaterialEnums;
use App\Enums\ProductEnums;
use App\Enums\SurfaceEnums;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Offcut>
 */
class OffcutFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'product_category' => ProductEnums::RHS->value,
            'material' => MaterialEnums::PLAIN_CARBON_STEEL->value,
            'grade' => GradeEnums::GR350->value,
            'surface' => SurfaceEnums::NONE->value,
            'nominal_length' => null,
            'precise_length' => null,
            'nominal_width' => "50",
            'precise_width' => null,
            'nominal_height' => "75",
            'precise_height' => null,
            'wall' => 2.5,
            'length' => null,
        ];
    }

    public function withProject($projectId = null)
    {
        return $this->state(function (array $attributes) use ($projectId) {
            return [
                'project_id' => $projectId ?? Project::factory(),
            ];
        });
    }

    public function withLength($length = null)
    {
        return $this->state(function (array $attributes) use ($length) {
            return [
                'length' => $length ?? 1000,
            ];
        });
    }
}
