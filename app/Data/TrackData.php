<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class TrackData extends Data
{
    public function __construct(
        public ?string $videoId,
        public string $title,
        public string $artists,
        public ?string $album,
        public ?string $duration,
        public ?int $durationSeconds,
        public ?string $thumbnailUrl,
        public bool $isExplicit,
        public bool $isAvailable,
    ) {}
}
