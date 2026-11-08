<?php

declare(strict_types=1);

namespace App\Services\Music\Data;

/**
 * An artist as listed in catalog search results and related-artist shelves.
 */
final readonly class CatalogArtistSummary
{
    public function __construct(
        public ProviderRef $ref,
        public string $name,
        public ?string $thumbnailUrl,
        public ?string $subscribers,
    ) {}
}
