<?php

declare(strict_types=1);

namespace App\Services\Metadata\Data;

/**
 * A title and artist as asked of a metadata service.
 */
final readonly class TrackQuery
{
    public function __construct(
        public string $title,
        public string $artist,
    ) {}
}
