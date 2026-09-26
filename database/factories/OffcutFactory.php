<?php

namespace Database\Factories;

use App\Enums\GradeEnums;
use App\Enums\MaterialEnums;
use App\Enums\ProductEnums;
use App\Enums\SurfaceEnums;
use App\Formatters\UniqueLetterIDGenerator;
use App\Models\Batch;
use App\Models\Piece;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Offcut>
 */
class OffcutFactory extends Factory
{
    /**
     * Every offcut this factory made used to be marked "ABC", so no fixture could ever stand in for
     * two different pieces of steel - and a regression that handed the same mark out twice would have
     * looked exactly like normal test data.
     *
     * Four letters, because the generator only reaches that length once a three-letter pool is full:
     * a factory offcut can never collide with a generated one.
     */
    private static int $markSequence = 0;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'batch_from_id' => null,
            'batch_to_id' => null,
            'business_id' => null,
            'piece_to_id' => null,

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

            "unique_mark" => UniqueLetterIDGenerator::codeFromIndex(self::$markSequence++, 4),
        ];
    }

    public function withBusiness($businessId = null)
    {
        return $this->state(function (array $attributes) use ($businessId) {
            return [
                'business_id' => $businessId ?? null,
            ];
        });
    }

    public function withBatchFrom($batchId = null)
    {
        return $this->state(function (array $attributes) use ($batchId) {
            return [
                'batch_from_id' => $batchId ?? Batch::factory(),
            ];
        });
    }

    public function withBatchTo($batchId = null)
    {
        return $this->state(function (array $attributes) use ($batchId) {
            return [
                'batch_to_id' => $batchId ?? null,
            ];
        });
    }

    public function withPieceTo($pieceId = null)
    {
        return $this->state(function (array $attributes) use ($pieceId) {
            return [
                'piece_to_id' => $pieceId ?? null,
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
