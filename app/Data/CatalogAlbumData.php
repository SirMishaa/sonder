<?php

declare(strict_types=1);

namespace App\Data;

use App\Services\Music\Data\CatalogAlbumSummary;
use App\Services\Music\Data\RemoteArtist;
use App\Services\Thumbnails\ThumbnailProxy;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class CatalogAlbumData extends Data
{
    /**
     * @param  int|null  $trackCount  null until the album's tracks are counted
     */
    public function __construct(
        public string $id,
        public string $title,
        public string $type,
        public ?int $year,
        public string $artists,
        public ?string $artistId,
        public ?string $thumbnailUrl,
        public ?int $trackCount,
    ) {}

    public static function fromSummary(CatalogAlbumSummary $album, ?int $trackCount = null): self
    {
        return new self(
            id: $album->ref->externalId,
            title: $album->title,
            type: $album->type,
            year: $album->year,
            artists: implode(', ', array_map(fn (RemoteArtist $artist): string => $artist->name, $album->artists)),
            artistId: ($album->artists[0] ?? null)?->ref?->externalId,
            thumbnailUrl: ThumbnailProxy::url($album->thumbnailUrl),
            trackCount: $trackCount,
        );
    }
}
