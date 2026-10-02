<?php

declare(strict_types=1);

namespace App\Services\Metadata\Data;

/**
 * A track as Last.fm corrected it. Last.fm often does not know the length.
 */
final readonly class LastFmTrack
{
    public function __construct(
        public string $title,
        public string $artist,
        public ?int $durationSeconds,
    ) {}
}
