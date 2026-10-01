<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\Provider;
use App\Enums\ProviderErrorCode;
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
     * Failures are stored as a ProviderErrorCode and translated when shown,
     * in the viewer's locale. Rows written before codes existed hold an
     * English sentence, translated through the JSON catalogue as before.
     */
    private static function translate(?string $message): ?string
    {
        if ($message === null) {
            return null;
        }

        $code = ProviderErrorCode::tryFrom($message);

        if ($code !== null) {
            return $code->userMessage(Provider::YouTubeMusic);
        }

        $translation = __($message);

        return is_string($translation) ? $translation : $message;
    }
}
