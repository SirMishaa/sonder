<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\ListenOrigin;
use App\Models\PlayerQueue;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The saved player queue, handed to the browser once when the app loads.
 */
#[TypeScript]
final class PlayerQueueData extends Data
{
    /**
     * @param  array<int, QueueTrackData>  $tracks
     * @param  int  $index  The current track's place in `tracks`; -1 when nothing is loaded.
     */
    public function __construct(
        public array $tracks,
        public int $index,
        public ?QueueSourceData $source,
        public ListenOrigin $origin,
        public int $version,
    ) {}

    public static function fromModel(PlayerQueue $queue): self
    {
        return new self(
            tracks: array_map(
                fn (array $track): QueueTrackData => new QueueTrackData(
                    key: $track['key'],
                    videoId: $track['videoId'],
                    title: $track['title'],
                    artists: $track['artists'],
                    album: $track['album'],
                    duration: $track['duration'],
                    durationSeconds: $track['durationSeconds'],
                    thumbnailUrl: $track['thumbnailUrl'],
                    playlistId: $track['playlistId'],
                    queued: $track['queued'] ?? false,
                    genres: $track['genres'] ?? [],
                ),
                $queue->tracks,
            ),
            index: $queue->current_index,
            source: $queue->source !== null
                ? new QueueSourceData($queue->source['playlistId'], $queue->source['title'])
                : null,
            origin: $queue->origin,
            version: $queue->version,
        );
    }
}
