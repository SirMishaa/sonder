<?php

declare(strict_types=1);

namespace App\Services\Music\Data;

/**
 * An album, single or EP as listed in the catalog. The type is the provider's own label.
 */
final readonly class CatalogAlbumSummary
{
    /**
     * @param  list<RemoteArtist>  $artists
     */
    public function __construct(
        public ProviderRef $ref,
        public string $title,
        public string $type,
        public ?int $year,
        public array $artists,
        public ?string $thumbnailUrl,
        public bool $isExplicit,
    ) {}
}
