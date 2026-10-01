<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Provider;
use App\Enums\ResolutionMethod;
use App\Enums\ResolutionStatus;
use App\Models\Recording;
use App\Models\RecordingResolution;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecordingResolution>
 */
final class RecordingResolutionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider' => Provider::YouTubeMusic,
            'external_id' => fake()->regexify('[A-Za-z0-9_-]{11}'),
            'recording_id' => Recording::factory(),
            'status' => ResolutionStatus::Resolved,
            'method' => ResolutionMethod::CreditsFm,
            'confidence' => 1.0,
            'query_title' => fake()->sentence(3),
            'query_artist' => fake()->name(),
            'resolved_at' => now(),
            'next_attempt_at' => now()->addDays(90),
        ];
    }
}
