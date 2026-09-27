<?php

declare(strict_types=1);

namespace App\Data;

use App\Models\Playlist;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * How current our copy of a playlist is. Dates are ISO 8601 strings.
 */
#[TypeScript]
final class PlaylistSyncStateData extends Data
{
    /**
     * @param  string  $lastCheckedAt  When YouTube Music was last asked about the playlist.
     * @param  string|null  $lastChangedAt  When its content last actually changed.
     * @param  string|null  $removedAt  When it was found missing from the library.
     */
    public function __construct(
        public string $lastCheckedAt,
        public ?string $lastChangedAt,
        public ?string $removedAt,
    ) {}

    public static function fromModel(Playlist $playlist): self
    {
        return new self(
            lastCheckedAt: $playlist->last_checked_at->toIso8601String(),
            lastChangedAt: $playlist->last_changed_at?->toIso8601String(),
            removedAt: $playlist->removed_at?->toIso8601String(),
        );
    }
}
