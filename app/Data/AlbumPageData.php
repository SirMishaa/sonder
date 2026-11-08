<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class AlbumPageData extends Data
{
    /**
     * @param  list<string>  $genres
     * @param  list<CatalogTrackData>  $tracks
     */
    public function __construct(
        public CatalogAlbumData $album,
        public ?string $description,
        public ?int $durationSeconds,
        public int $totalPlays,
        public array $genres,
        public int $inLibraryCount,
        public array $tracks,
    ) {}
}
