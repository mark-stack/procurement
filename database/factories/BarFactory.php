<?php

namespace Database\Factories;

use App\Enums\GradeEnums;
use App\Enums\MaterialEnums;
use App\Enums\ProductEnums;
use App\Enums\SurfaceEnums;
use App\Models\Batch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Bar>
 */
class BarFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * A 9,000mm 200PFC, which is the section the nesting tests are written around - so a bar made here
     * matches the offcuts OffcutFactory and the pieces in Pest.php produce.
     *
     * order_id and heat_number are null: a bar exists from the moment a batch is nested, and neither
     * the purchase nor the heat is known until somebody places an order and books the steel in.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'batch_id' => Batch::factory(),
            'order_id' => null,
            'heat_number' => null,

            'product_category' => ProductEnums::PFC->value,
            'material' => MaterialEnums::PLAIN_CARBON_STEEL->value,
            'grade' => GradeEnums::GR300->value,
            'surface' => SurfaceEnums::NONE->value,
            'nominal_length' => '9000',
            'precise_length' => null,
            'nominal_width' => null,
            'precise_width' => null,
            'nominal_height' => '200',
            'precise_height' => null,
            'wall' => null,

            'product_derived_label' => '200PFC',
            'length' => 9000,
        ];
    }

    public function forBatch(int $batchId): self
    {
        return $this->state(fn (array $attributes) => [
            'batch_id' => $batchId,
        ]);
    }

    public function forOrder(int $orderId): self
    {
        return $this->state(fn (array $attributes) => [
            'order_id' => $orderId,
        ]);
    }

    public function withHeatNumber(string $heatNumber): self
    {
        return $this->state(fn (array $attributes) => [
            'heat_number' => $heatNumber,
        ]);
    }
}
