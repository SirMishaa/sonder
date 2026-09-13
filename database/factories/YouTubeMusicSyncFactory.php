<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\YouTubeMusicSyncStatus;
use App\Models\YouTubeMusicAccount;
use App\Models\YouTubeMusicSync;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<YouTubeMusicSync>
 */
final class YouTubeMusicSyncFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'youtube_music_account_id' => YouTubeMusicAccount::factory(),
            'status' => YouTubeMusicSyncStatus::Pending,
            'synced_playlists' => 0,
        ];
    }
}
