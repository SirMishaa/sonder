<?php

declare(strict_types=1);

use App\Data\YouTubeMusicSyncData;
use App\Enums\YouTubeMusicSyncStatus;
use App\Models\YouTubeMusicSync;

it('maps a sync model to its data representation', function (): void {
    $sync = YouTubeMusicSync::factory()->create([
        'status' => YouTubeMusicSyncStatus::Syncing,
        'total_playlists' => 10,
        'synced_playlists' => 3,
        'current_playlist_title' => 'Deep Focus',
        'error_message' => null,
    ]);

    $data = YouTubeMusicSyncData::fromModel($sync);

    expect($data->id)->toBe($sync->id)
        ->and($data->status)->toBe(YouTubeMusicSyncStatus::Syncing)
        ->and($data->totalPlaylists)->toBe(10)
        ->and($data->syncedPlaylists)->toBe(3)
        ->and($data->currentPlaylistTitle)->toBe('Deep Focus')
        ->and($data->errorMessage)->toBeNull();
});
