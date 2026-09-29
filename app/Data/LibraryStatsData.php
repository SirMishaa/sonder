<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Figures computed from the synced library itself, not from listening history
 * (which YouTube Music does not expose).
 */
#[TypeScript]
final class LibraryStatsData extends Data
{
    /**
     * @param  array<int, ArtistTallyData>  $topArtists
     */
    public function __construct(
        public int $playlistCount,
        public int $trackCount,
        public int $artistCount,
        public int $totalHours,
        public array $topArtists,
    ) {}
}
