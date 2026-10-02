<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\SavePlayerQueue;
use App\Http\Requests\UpdatePlayerQueueRequest;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final readonly class PlayerQueueController
{
    public function update(UpdatePlayerQueueRequest $request, #[CurrentUser] User $user, SavePlayerQueue $save): JsonResponse
    {
        /** @var array{tracks: array<int, array{key: string, videoId?: string|null, title: string, artists?: string|null, album?: string|null, duration?: string|null, durationSeconds: int, thumbnailUrl?: string|null, playlistId?: string|null, queued?: bool, genres?: list<string>}>, index: int, source: array{playlistId: string|null, title: string}|null, origin: string} $attributes */
        $attributes = $request->validated();

        return response()->json(['version' => $save->handle($user, $attributes)]);
    }
}
