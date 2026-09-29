<?php

declare(strict_types=1);

namespace App\Data;

use App\Models\Track;
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

    public static function fromModel(Track $track): self
    {
        return new self(
            videoId: $track->youtube_video_id,
            title: $track->title,
            artists: $track->artists,
            album: $track->album,
            duration: $track->duration,
            durationSeconds: $track->duration_seconds,
            thumbnailUrl: $track->thumbnail_url,
            isExplicit: $track->is_explicit,
            isAvailable: $track->is_available,
        );
    }
}
