<?php

namespace Database\Factories;

use App\Models\Bar;
use App\Models\Batch;
use App\Models\Offcut;
use App\Models\Piece;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Cut>
 */
class CutFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * Deliberately NOT a complete cut: bar_id and offcut_id are both null here, and Cut::booted
     * refuses to save a row that names neither. A test has to say where the steel came from, because
     * that is the only thing this row is for - a default would let a test pass while recording a cut
     * that traces nothing.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'piece_id' => Piece::factory(),
            'batch_id' => Batch::factory(),
            'bar_id' => null,
            'offcut_id' => null,
            'length' => 2500,
        ];
    }

    public function fromBar(?int $barId = null): self
    {
        return $this->state(fn (array $attributes) => [
            'bar_id' => $barId ?? Bar::factory(),
            'offcut_id' => null,
        ]);
    }

    public function fromOffcut(?int $offcutId = null): self
    {
        return $this->state(fn (array $attributes) => [
            'bar_id' => null,
            'offcut_id' => $offcutId ?? Offcut::factory(),
        ]);
    }

    public function withLength(float $length): self
    {
        return $this->state(fn (array $attributes) => [
            'length' => $length,
        ]);
    }
}
