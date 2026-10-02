<?php

declare(strict_types=1);

namespace App\Services\Metadata\Data;

/**
 * An artist a source finds similar, with its match from 0 to 1.
 */
final readonly class SimilarArtist
{
    public function __construct(
        public string $name,
        public float $match,
    ) {}
}
