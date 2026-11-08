<?php

declare(strict_types=1);

namespace App\Data;

use App\Services\Music\Data\CatalogPlaylistSummary;
use App\Services\Music\Data\RemotePlaylistSummary;
use App\Services\Thumbnails\ThumbnailProxy;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class CatalogPlaylistData extends Data
{
    public function __construct(
        public string $id,
        public string $title,
        public ?string $author,
        public ?int $trackCount,
        public ?string $thumbnailUrl,
    ) {}

    public static function fromSummary(CatalogPlaylistSummary $playlist): self
    {
        return new self(
            id: $playlist->ref->externalId,
            title: $playlist->title,
            author: $playlist->author,
            trackCount: $playlist->trackCount,
            thumbnailUrl: ThumbnailProxy::url($playlist->thumbnailUrl),
        );
    }

    public static function fromRemote(RemotePlaylistSummary $playlist): self
    {
        return new self(
            id: $playlist->ref->externalId,
            title: $playlist->title,
            author: $playlist->author,
            trackCount: $playlist->trackCount,
            thumbnailUrl: ThumbnailProxy::url($playlist->thumbnailUrl),
        );
    }
}
