<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The connected library, shared with every page for the sidebar.
 */
#[TypeScript]
final class LibraryData extends Data
{
    /**
     * @param  array<int, LibraryPlaylistData>  $playlists
     * @param  string|null  $lastCheckedAt  ISO 8601; when YouTube Music was last asked about the library.
     */
    public function __construct(
        public string $accountName,
        public array $playlists,
        public ?string $lastCheckedAt,
        public ?YouTubeMusicSyncData $activeSync,
    ) {}
}
