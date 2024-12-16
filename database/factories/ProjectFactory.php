<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\User;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Project>
 */
class ProjectFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            "name" => fake()->text(10)." in ".fake()->city(),
            "user_id" => User::factory(),
            "awarded" => true,
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
}
