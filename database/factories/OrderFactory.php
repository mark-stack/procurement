<?php

namespace Database\Factories;

use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Order>
 */
class OrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'project_id' => null,
            'quote_id' => null,
            'supplier_id' => Supplier::factory(),
        ];
    }

    public function forUser($userId = null)
    {
        return $this->state(function (array $attributes) use ($userId) {
            return [
                'user_id' => $userId ?? User::factory(),
            ];
        });
    }

    public function forProject($projectId = null)
    {
        return $this->state(function (array $attributes) use ($projectId) {
            return [
                'project_id' => $projectId,
            ];
        });
    }

    public function forQuote($quoteId = null)
    {
        return $this->state(function (array $attributes) use ($quoteId) {
            return [
                'quote_id' => $quoteId,
            ];
        });
    }

    public function forSupplier($supplierId = null)
    {
        return $this->state(function (array $attributes) use ($supplierId) {
            return [
                'supplier_id' => $supplierId ?? Supplier::factory(),
            ];
        });
    }
}
