<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class PublicPlaylistPageData extends Data
{
    /**
     * @param  list<CatalogTrackData>  $tracks
     */
    public function __construct(
        public CatalogPlaylistData $playlist,
        public ?string $description,
        public array $tracks,
    ) {}
}
