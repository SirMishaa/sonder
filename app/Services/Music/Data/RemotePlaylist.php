<?php

declare(strict_types=1);

namespace App\Services\Music\Data;

/**
 * A playlist with its tracks, in the provider's order.
 */
final readonly class RemotePlaylist
{
    /**
     * @param  list<RemoteTrack>  $tracks
     */
    public function __construct(
        public RemotePlaylistSummary $summary,
        public array $tracks,
    ) {}
}
