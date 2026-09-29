<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\YouTubeMusicSyncStatus;
use App\Models\YouTubeMusicSync;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class YouTubeMusicSyncData extends Data
{
    public function __construct(
        public string $id,
        public YouTubeMusicSyncStatus $status,
        public ?int $totalPlaylists,
        public int $syncedPlaylists,
        public ?string $currentPlaylistTitle,
        public ?string $errorMessage,
    ) {}

    public static function fromModel(YouTubeMusicSync $sync): self
    {
        return new self(
            id: $sync->id,
            status: $sync->status,
            totalPlaylists: $sync->total_playlists,
            syncedPlaylists: $sync->synced_playlists,
            currentPlaylistTitle: $sync->current_playlist_title,
            errorMessage: self::translate($sync->error_message),
        );
    }

    /**
     * Error messages are stored in English, as thrown, and translated when
     * shown so a sync that failed before a language change reads correctly.
     */
    private static function translate(?string $message): ?string
    {
        if ($message === null) {
            return null;
        }

        $translation = __($message);

        return is_string($translation) ? $translation : $message;
    }
}
