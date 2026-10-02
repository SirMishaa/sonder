<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One entry of the player queue, as the browser stores it.
 */
#[TypeScript]
final class QueueTrackData extends Data
{
    /**
     * @param  string  $key  Unique within the queue: the track's place in its list, plus a suffix when queued by hand.
     * @param  bool  $queued  Added by hand to play after the current track.
     * @param  list<string>  $genres  strongest first, at most two
     */
    public function __construct(
        public string $key,
        public ?string $videoId,
        public string $title,
        public string $artists,
        public ?string $album,
        public ?string $duration,
        public int $durationSeconds,
        public ?string $thumbnailUrl,
        public ?string $playlistId,
        public bool $queued = false,
        public array $genres = [],
    ) {}
}
