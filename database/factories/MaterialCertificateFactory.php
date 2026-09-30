<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\MaterialCertificate>
 */
class MaterialCertificateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * The path points at nothing. A test that cares about the file itself uploads one through the
     * controller against a faked disk - this is for the rows alone.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $filename = 'cert-'.$this->faker->unique()->numberBetween(1000, 9999).'.pdf';

        return [
            'order_id' => Order::factory(),
            'user_id' => User::factory(),
            'path' => 'material-certificates/'.$this->faker->uuid().'.pdf',
            'original_filename' => $filename,
            'mime_type' => 'application/pdf',
            'size_bytes' => $this->faker->numberBetween(20_000, 900_000),
        ];
    }

    public function forOrder($orderId = null)
    {
        return $this->state(fn (array $attributes) => [
            'order_id' => $orderId ?? Order::factory(),
        ]);
    }
}
