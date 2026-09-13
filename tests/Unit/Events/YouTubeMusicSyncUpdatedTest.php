<?php

declare(strict_types=1);

use App\Enums\YouTubeMusicSyncStatus;
use App\Events\YouTubeMusicSyncUpdated;
use App\Models\YouTubeMusicSync;
use Illuminate\Broadcasting\PrivateChannel;

it('broadcasts on a private channel scoped to the sync', function (): void {
    $sync = YouTubeMusicSync::factory()->create();

    $event = new YouTubeMusicSyncUpdated($sync);
    $channels = $event->broadcastOn();

    expect($channels)->toHaveCount(1)
        ->and($channels[0])->toBeInstanceOf(PrivateChannel::class)
        ->and($channels[0]->name)->toBe('private-youtube-music-sync.'.$sync->id)
        ->and($event->broadcastAs())->toBe('sync.updated');
});

it('broadcasts the sync as data', function (): void {
    $sync = YouTubeMusicSync::factory()->create([
        'status' => YouTubeMusicSyncStatus::Syncing,
        'synced_playlists' => 2,
        'total_playlists' => 5,
    ]);

    $payload = (new YouTubeMusicSyncUpdated($sync))->broadcastWith();

    expect($payload)->toMatchArray([
        'id' => $sync->id,
        'status' => 'syncing',
        'totalPlaylists' => 5,
        'syncedPlaylists' => 2,
    ]);
});
