<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\PlayerQueue;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class SavePlayerQueue
{
    /**
     * Replace the user's saved queue with the browser's copy. The last save
     * wins; the returned version lets the browser know its copy is current.
     *
     * @param  array{tracks: array<int, array{key: string, videoId?: string|null, title: string, artists?: string|null, album?: string|null, duration?: string|null, durationSeconds: int, thumbnailUrl?: string|null, playlistId?: string|null, queued?: bool, genres?: list<string>}>, index: int, source: array{playlistId: string|null, title: string}|null, origin: string}  $attributes
     */
    public function handle(User $user, array $attributes): int
    {
        $tracks = array_map(fn (array $track): array => [
            ...$track,
            'videoId' => $track['videoId'] ?? null,
            'artists' => $track['artists'] ?? '',
            'album' => $track['album'] ?? null,
            'duration' => $track['duration'] ?? null,
            'thumbnailUrl' => $track['thumbnailUrl'] ?? null,
            'playlistId' => $track['playlistId'] ?? null,
            'queued' => $track['queued'] ?? false,
            'genres' => $track['genres'] ?? [],
        ], $attributes['tracks']);

        return DB::transaction(function () use ($user, $attributes, $tracks): int {
            $queue = PlayerQueue::query()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            $version = ($queue->version ?? 0) + 1;

            PlayerQueue::query()->updateOrCreate(
                ['user_id' => $user->id],
                [
                    'tracks' => $tracks,
                    'current_index' => $attributes['index'],
                    'source' => $attributes['source'],
                    'origin' => $attributes['origin'],
                    'version' => $version,
                ],
            );

            return $version;
        });
    }
}
