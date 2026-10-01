<?php

declare(strict_types=1);

namespace App\Services\Metadata\Data;

/**
 * A recording as MusicBrainz describes it.
 */
final readonly class RegistryRecording
{
    /**
     * @param  list<array{mbid: string, name: string}>  $artists
     * @param  list<string>  $isrcs
     * @param  int|null  $score  0–100, search results only
     * @param  string|null  $firstReleaseDate  Y-m-d, partial dates pinned to their first day
     * @param  string|null  $disambiguation  e.g. "live, 2012-09-30: Roundhouse", "instrumental"
     */
    public function __construct(
        public string $mbid,
        public string $title,
        public ?int $durationSeconds,
        public array $artists,
        public array $isrcs = [],
        public ?int $score = null,
        public ?string $firstReleaseDate = null,
        public ?string $disambiguation = null,
    ) {}
}
