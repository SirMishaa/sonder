<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Recording;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Recording>
 */
final class RecordingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'mbid' => fake()->uuid(),
            'isrc' => fake()->regexify('[A-Z]{2}[A-Z0-9]{3}[0-9]{7}'),
            'title' => fake()->sentence(3),
            'artist_name' => fake()->name(),
            'duration_seconds' => fake()->numberBetween(120, 360),
        ];
    }
}
