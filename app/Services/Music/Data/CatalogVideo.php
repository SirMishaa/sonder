<?php

declare(strict_types=1);

namespace App\Services\Music\Data;

/**
 * A music video on an artist page.
 */
final readonly class CatalogVideo
{
    public function __construct(
        public ProviderRef $ref,
        public string $title,
        public ?string $thumbnailUrl,
        public ?int $views,
    ) {}
}
