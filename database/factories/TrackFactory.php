<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Playlist;
use App\Models\Track;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Track>
 */
final class TrackFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'playlist_id' => Playlist::factory(),
            'youtube_video_id' => fake()->regexify('[A-Za-z0-9_-]{11}'),
            'title' => fake()->sentence(3),
            'artists' => fake()->name(),
            'album' => fake()->words(2, true),
            'is_explicit' => false,
            'is_available' => true,
            'position' => 0,
        ];
    }
}
