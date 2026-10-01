<?php

declare(strict_types=1);

namespace App\Services\Metadata\Data;

/**
 * What credits.fm knows about one ISRC.
 */
final readonly class IsrcDetail
{
    /**
     * @param  list<string>  $artists
     * @param  string|null  $releaseDate  Y-m-d
     */
    public function __construct(
        public string $isrc,
        public string $title,
        public array $artists,
        public ?string $iswc,
        public ?string $releaseDate,
    ) {}
}
