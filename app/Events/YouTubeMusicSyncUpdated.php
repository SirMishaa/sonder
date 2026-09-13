<?php

declare(strict_types=1);

namespace App\Events;

use App\Data\YouTubeMusicSyncData;
use App\Models\YouTubeMusicSync;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

final class YouTubeMusicSyncUpdated implements ShouldBroadcastNow
{
    public function __construct(public readonly YouTubeMusicSync $sync) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('youtube-music-sync.'.$this->sync->id)];
    }

    public function broadcastAs(): string
    {
        return 'sync.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return YouTubeMusicSyncData::fromModel($this->sync)->toArray();
    }
}
