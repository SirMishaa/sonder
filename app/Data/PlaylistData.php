<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class PlaylistData extends Data
{
    /**
     * @param  array<int, TrackData>  $tracks
     */
    public function __construct(
        public string $id,
        public string $title,
        public ?string $description,
        public ?int $trackCount,
        public ?string $duration,
        public ?string $thumbnailUrl,
        public ?string $author,
        public array $tracks,
    ) {}
}
