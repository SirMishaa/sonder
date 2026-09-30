<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ListenEndReason;
use App\Enums\ListenOrigin;
use App\Models\Listen;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Listen>
 */
final class ListenFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $duration = fake()->numberBetween(120, 360);
        $listened = fake()->numberBetween(0, $duration);
        $startedAt = now()->subMinutes(fake()->numberBetween(5, 600));

        return [
            'user_id' => User::factory(),
            'youtube_video_id' => fake()->regexify('[A-Za-z0-9_-]{11}'),
            'title' => fake()->sentence(3),
            'artists' => fake()->name(),
            'youtube_playlist_id' => 'PL'.fake()->regexify('[A-Za-z0-9]{16}'),
            'origin' => ListenOrigin::Playlist,
            'end_reason' => ListenEndReason::Ended,
            'started_at' => $startedAt,
            'ended_at' => $startedAt->copy()->addSeconds($listened),
            'position_seconds' => $listened,
            'listened_seconds' => $listened,
            'duration_seconds' => $duration,
        ];
    }
}
