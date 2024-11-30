<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Quote>
 */
class QuoteFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            "user_id" => User::factory(),
            "supplier_id" => Supplier::factory(),
            "project_id" => null,
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

    public function forSupplier($supplierId = null)
    {
        return $this->state(function (array $attributes) use ($supplierId) {
            return [
                'supplier_id' => $supplierId ?? Supplier::factory(),
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
}
