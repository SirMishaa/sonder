<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\Providers\ProviderException;
use App\Models\Playlist;
use App\Services\Music\Contracts\ReadsPlaylists;
use App\Services\Music\ProviderRegistry;

/**
 * The manual refresh of one playlist, for edits its listing fingerprint cannot reveal.
 */
final readonly class RefreshPlaylist
{
    public function __construct(
        private ProviderRegistry $providers,
        private SyncPlaylistTracks $syncTracks,
    ) {}

    /**
     * Re-reads one playlist regardless of its fingerprint, to catch edits the
     * library listing cannot reveal. The fingerprint is left alone on purpose:
     * it describes the library listing, which this call does not read.
     *
     * @return bool Whether anything about the playlist changed.
     *
     * @throws ProviderException
     */
    public function handle(Playlist $playlist): bool
    {
        $account = $playlist->youtubeMusicAccount()->firstOrFail();

        $playlistData = $this->providers->require($account->provider(), ReadsPlaylists::class)
            ->playlist($account->credentials(), $playlist->youtube_playlist_id, $playlist->track_count);

        $playlist->last_checked_at = now();

        return $this->syncTracks->handle($playlist, $playlistData);
    }
}
