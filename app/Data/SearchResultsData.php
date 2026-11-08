<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class SearchResultsData extends Data
{
    /**
     * @param  list<CatalogTrackData>  $topArtistLibraryTracks
     * @param  list<CatalogTrackData>  $tracks
     * @param  list<CatalogAlbumData>  $albums
     * @param  list<CatalogArtistData>  $artists
     * @param  list<CatalogPlaylistData>  $playlists
     */
    public function __construct(
        public ?CatalogArtistData $topArtist,
        public ?ArtistInLibraryData $topArtistInLibrary,
        public array $topArtistLibraryTracks,
        public array $tracks,
        public array $albums,
        public array $artists,
        public array $playlists,
    ) {}
}
