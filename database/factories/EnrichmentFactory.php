<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\EnrichmentStatus;
use App\Enums\MetadataSource;
use App\Models\Enrichment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Enrichment>
 */
final class EnrichmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subject_type' => Enrichment::RECORDING,
            'subject_key' => fake()->uuid(),
            'source' => MetadataSource::MusicBrainz,
            'endpoint' => 'recording',
            'status' => EnrichmentStatus::Done,
            'payload' => [],
            'fetched_at' => now(),
            'next_attempt_at' => now()->addDays(90),
        ];
    }
}
