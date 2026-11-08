<?php

declare(strict_types=1);

namespace App\Services\Music\Data;

/**
 * An album page: details and tracks, with play counts keyed by video id.
 */
final readonly class CatalogAlbum
{
    /**
     * @param  list<RemoteTrack>  $tracks
     * @param  array<string, int>  $playsByVideoId
     */
    public function __construct(
        public CatalogAlbumSummary $summary,
        public ?string $description,
        public int $trackCount,
        public ?int $durationSeconds,
        public array $tracks,
        public array $playsByVideoId,
    ) {}
}
