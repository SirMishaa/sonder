<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Playlist;
use App\Models\YouTubeMusicAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Playlist>
 */
final class PlaylistFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'youtube_music_account_id' => YouTubeMusicAccount::factory(),
            'youtube_playlist_id' => fake()->uuid(),
            'title' => fake()->words(3, true),
            'last_checked_at' => now(),
        ];
    }

    /**
     * A playlist that no longer exists in the YouTube Music library.
     */
    public function removed(): static
    {
        return $this->state(fn (): array => [
            'removed_at' => now(),
        ]);
    }
}
