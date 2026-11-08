<?php

declare(strict_types=1);

namespace App\Data;

use App\Services\Music\Data\CatalogArtistSummary;
use App\Services\Thumbnails\ThumbnailProxy;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class CatalogArtistData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $thumbnailUrl,
        public ?string $subscribers,
    ) {}

    public static function fromSummary(CatalogArtistSummary $artist): self
    {
        return new self(
            id: $artist->ref->externalId,
            name: $artist->name,
            thumbnailUrl: ThumbnailProxy::url($artist->thumbnailUrl),
            subscribers: $artist->subscribers,
        );
    }
}
