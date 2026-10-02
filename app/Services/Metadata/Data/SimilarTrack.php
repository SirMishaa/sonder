<?php

declare(strict_types=1);

namespace App\Services\Metadata\Data;

/**
 * A track a source finds similar, with its match from 0 to 1.
 */
final readonly class SimilarTrack
{
    public function __construct(
        public string $title,
        public string $artist,
        public float $match,
    ) {}
}
