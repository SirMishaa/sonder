<?php

declare(strict_types=1);

namespace App\Data;

use App\Services\Music\Data\CatalogVideo;
use App\Services\Thumbnails\ThumbnailProxy;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class CatalogVideoData extends Data
{
    public function __construct(
        public ?string $videoId,
        public string $title,
        public ?string $thumbnailUrl,
        public ?int $views,
    ) {}

    public static function fromCatalog(CatalogVideo $video): self
    {
        return new self(
            videoId: $video->ref->externalId,
            title: $video->title,
            thumbnailUrl: ThumbnailProxy::url($video->thumbnailUrl),
            views: $video->views,
        );
    }
}
