<?php

declare(strict_types=1);

namespace App\Services\Music\Data;

/**
 * An artist page: profile, top tracks and discography.
 */
final readonly class CatalogArtist
{
    /**
     * @param  list<RemoteTrack>  $topTracks
     * @param  list<CatalogAlbumSummary>  $albums
     * @param  list<CatalogAlbumSummary>  $singles
     * @param  list<CatalogVideo>  $videos
     * @param  list<CatalogArtistSummary>  $related
     */
    public function __construct(
        public CatalogArtistSummary $summary,
        public ?string $monthlyListeners,
        public ?int $views,
        public ?string $description,
        public array $topTracks,
        public array $albums,
        public array $singles,
        public array $videos,
        public array $related,
    ) {}
}
