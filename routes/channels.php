<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\YouTubeMusicSync;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('youtube-music-sync.{syncId}', function (User $user, string $syncId): bool {
    $sync = YouTubeMusicSync::query()->find($syncId);

    return $sync !== null && $sync->isOwnedBy($user);
});

Broadcast::channel('library-enrichment.{userId}', fn (User $user, string $userId): bool => $user->id === $userId);
