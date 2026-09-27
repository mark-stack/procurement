<?php

namespace Database\Factories;

use App\Enums\GradeEnums;
use App\Enums\MaterialEnums;
use App\Enums\MeasurementUnitEnums;
use App\Enums\ProductEnums;
use App\Enums\SurfaceEnums;
use App\Models\Project;
use App\Models\RawMaterialQuote;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Piece>
 */
class PieceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * A 9m 200PFC, the same shape the tests' pieceOnBatch() helper builds by hand. This file used to
     * hold the bare column list it was scaffolded from - no values, no commas - so it was a parse
     * error, and the first call to Piece::factory() would have been fatal rather than just wrong.
     *
     * order_id and batch_id stay null, which is an un-nested piece: what piecesReadyForBatching()
     * looks for. RawMaterialQuote has no factory of its own, so the row a piece cannot exist without
     * is built here, against the same project.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $project = Project::factory();

        return [
            'project_id' => $project,
            'raw_material_quote_id' => fn (array $attributes) => RawMaterialQuote::create([
                'csv_index' => 1,
                'description' => '200PFC',
                'product_category' => ProductEnums::PFC->value,
                'material' => MaterialEnums::PLAIN_CARBON_STEEL->value,
                'grade' => GradeEnums::GR300->value,
                'surface' => SurfaceEnums::NONE->value,
                'nominal_units' => MeasurementUnitEnums::MILLIMETERS->value,
                'length_required' => '9000',
                'sub_qty' => '1',
                'project_id' => $attributes['project_id'],
                'assembly_mark' => '',
            ])->id,
            'order_id' => null,
            'batch_id' => null,
            'product_category' => ProductEnums::PFC->value,
            'material' => MaterialEnums::PLAIN_CARBON_STEEL->value,
            'grade' => GradeEnums::GR300->value,
            'surface' => SurfaceEnums::NONE->value,
            'nominal_units' => MeasurementUnitEnums::MILLIMETERS->value,
            'nominal_height' => '200',
            'actual_length' => '9000',
        ];
    }
}
