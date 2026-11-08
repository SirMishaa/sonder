<?php

declare(strict_types=1);

namespace App\Data;

use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * What one user's library and listening history hold of an artist.
 */
#[TypeScript]
final class ArtistInLibraryData extends Data
{
    /**
     * @param  list<array{title: string, listens: int}>  $topTracks  most listened first, at most four
     */
    public function __construct(
        public int $trackCount,
        public int $albumCount,
        public int $listenCount,
        public int $listenedMinutes,
        public ?CarbonInterface $firstListenedAt,
        public ?CarbonInterface $lastListenedAt,
        public array $topTracks,
    ) {}
}
