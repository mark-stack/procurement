<?php

namespace Database\Factories;

use App\Models\User;
use App\Notifications\QuoteDueEmail;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\NotificationDelivery>
 */
class NotificationDeliveryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * A bell delivery of a real notification class, because the admin screen labels rows from the
     * class name and a made-up one would read as a type this application does not have.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'notification_id' => Str::uuid()->toString(),
            'notifiable_type' => User::class,
            'notifiable_id' => User::factory(),
            'channel' => 'database',
            'type' => QuoteDueEmail::class,
            'recipient_email' => null,
            'subject' => null,
            'payload' => ['project_id' => 1, 'project_name' => 'Some project'],
        ];
    }

    public function email(): static
    {
        return $this->state(fn (array $attributes) => [
            'channel' => 'mail',
            'subject' => 'Procurement actions',
        ]);
    }
}
