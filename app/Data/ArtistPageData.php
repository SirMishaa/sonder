<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class ArtistPageData extends Data
{
    /**
     * @param  list<string>  $genres
     * @param  list<CatalogTrackData>  $tracks
     * @param  list<CatalogAlbumData>  $albums
     * @param  list<CatalogAlbumData>  $singles
     * @param  list<CatalogVideoData>  $videos
     * @param  list<CatalogArtistData>  $related
     */
    public function __construct(
        public CatalogArtistData $artist,
        public ?string $monthlyListeners,
        public ?int $views,
        public ?string $description,
        public array $genres,
        public array $tracks,
        public array $albums,
        public array $singles,
        public array $videos,
        public array $related,
        public ArtistInLibraryData $inLibrary,
    ) {}
}
