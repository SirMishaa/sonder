<?php

declare(strict_types=1);

namespace App\Data;

use App\Models\Playlist;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A playlist as the sidebar lists it: enough to link to it and flag its state.
 */
#[TypeScript]
final class LibraryPlaylistData extends Data
{
    /**
     * @param  string|null  $lastChangedAt  ISO 8601; when its content last actually changed.
     */
    public function __construct(
        public string $id,
        public string $title,
        public ?string $thumbnailUrl,
        public ?int $trackCount,
        public ?string $lastChangedAt,
        public bool $isRemoved,
    ) {}

    public static function fromModel(Playlist $playlist): self
    {
        return new self(
            id: $playlist->youtube_playlist_id,
            title: $playlist->title,
            thumbnailUrl: $playlist->thumbnail_url,
            trackCount: $playlist->track_count,
            lastChangedAt: $playlist->last_changed_at?->toIso8601String(),
            isRemoved: $playlist->removed_at !== null,
        );
    }
}
