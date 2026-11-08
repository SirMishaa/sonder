<?php

declare(strict_types=1);

namespace App\Services\Music\Data;

/**
 * What a catalog search found, grouped by kind.
 */
final readonly class CatalogResults
{
    /**
     * @param  list<RemoteTrack>  $tracks
     * @param  list<CatalogAlbumSummary>  $albums
     * @param  list<CatalogArtistSummary>  $artists
     * @param  list<CatalogPlaylistSummary>  $playlists
     */
    public function __construct(
        public ?CatalogArtistSummary $topArtist,
        public array $tracks,
        public array $albums,
        public array $artists,
        public array $playlists,
    ) {}

    public function isEmpty(): bool
    {
        return $this->topArtist === null
            && $this->tracks === []
            && $this->albums === []
            && $this->artists === []
            && $this->playlists === [];
    }
}
