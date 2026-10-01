<?php

declare(strict_types=1);

namespace App\Services\Music\Data;

/**
 * The release a remote track belongs to, as far as the provider tells.
 */
final readonly class RemoteAlbum
{
    public function __construct(
        public ?ProviderRef $ref,
        public string $title,
        public ?int $year,
    ) {}
}
