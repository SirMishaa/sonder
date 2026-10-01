<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Contributor;
use App\Support\MusicText;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contributor>
 */
final class ContributorFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->name();

        return [
            'name' => $name,
            'normalized_name' => MusicText::normalize($name),
            'mbid' => fake()->uuid(),
            'ipi' => null,
        ];
    }
}
