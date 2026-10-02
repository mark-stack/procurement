<?php

namespace Database\Factories;

use App\Models\MaterialListFile;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\MaterialListFile>
 */
class MaterialListFileFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * The path points at nothing, like MaterialCertificateFactory's. A test that cares about the file
     * itself uploads one through the controller against a faked disk - this is for the rows alone,
     * and for hanging material rows off something named.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $filename = 'bom-rev-'.$this->faker->randomLetter().'-'
            .$this->faker->unique()->numberBetween(1000, 9999).'.xlsx';

        return [
            'project_id' => Project::factory(),
            'user_id' => User::factory(),
            'path' => MaterialListFile::DIRECTORY.'/'.$this->faker->uuid().'.xlsx',
            'original_filename' => $filename,
            'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'size_bytes' => $this->faker->numberBetween(8_000, 400_000),
        ];
    }

    //A row whose file could not be stored, or whose file has since gone off the disk
    public function unstored(): static
    {
        return $this->state(fn (array $attributes) => [
            'path' => null,
        ]);
    }
}
