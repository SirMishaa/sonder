<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ListenOrigin;
use App\Models\PlayerQueue;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlayerQueue>
 */
final class PlayerQueueFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $tracks = collect(range(0, 2))->map(function (int $position): array {
            $videoId = fake()->regexify('[A-Za-z0-9_-]{11}');

            return [
                'key' => "{$videoId}#{$position}",
                'videoId' => $videoId,
                'title' => fake()->sentence(3),
                'artists' => fake()->name(),
                'album' => null,
                'duration' => '3:00',
                'durationSeconds' => 180,
                'thumbnailUrl' => null,
                'playlistId' => 'PL1',
            ];
        })->all();

        return [
            'user_id' => User::factory(),
            'tracks' => $tracks,
            'current_index' => 0,
            'source' => ['playlistId' => 'PL1', 'title' => fake()->words(2, true)],
            'origin' => ListenOrigin::Playlist,
            'version' => 1,
        ];
    }
}
