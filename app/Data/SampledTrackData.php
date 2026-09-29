<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A real track from the library, picked at random to stand in for features
 * that do not exist yet (discovery, suggestions). The front end treats these
 * as fixtures.
 */
#[TypeScript]
final class SampledTrackData extends Data
{
    public function __construct(
        public TrackData $track,
        public string $playlistId,
        public string $playlistTitle,
    ) {}
}
