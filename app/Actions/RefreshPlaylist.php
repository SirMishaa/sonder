<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Playlist;
use App\Services\YouTubeMusic\Client;

/**
 * Re-reads one playlist's tracks from YouTube Music regardless of its
 * fingerprint, to catch edits the library listing cannot reveal.
 *
 * The fingerprint is left alone on purpose: it describes the library listing,
 * and rewriting it from the playlist page's own metadata would make the next
 * library sync see a difference that is not there.
 */
final readonly class RefreshPlaylist
{
    public function __construct(
        private Client $client,
        private SyncPlaylistTracks $syncTracks,
    ) {}

    /**
     * @return bool Whether anything about the playlist changed.
     */
    public function handle(Playlist $playlist): bool
    {
        $playlistData = $this->client->playlist(
            $playlist->youtubeMusicAccount()->firstOrFail()->cookie,
            $playlist->youtube_playlist_id,
            $playlist->track_count,
        );

        $playlist->last_checked_at = now();

        return $this->syncTracks->handle($playlist, $playlistData);
    }
}
