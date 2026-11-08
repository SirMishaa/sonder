<?php

declare(strict_types=1);

namespace App\Services\Music\Data;

/**
 * A public playlist as listed in catalog search results.
 */
final readonly class CatalogPlaylistSummary
{
    public function __construct(
        public ProviderRef $ref,
        public string $title,
        public ?string $author,
        public ?int $trackCount,
        public ?string $thumbnailUrl,
    ) {}
}
