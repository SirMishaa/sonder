<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\PlaylistSummaryData;
use App\Exceptions\YouTubeMusicException;
use App\Exceptions\YouTubeMusicRateLimitedException;
use App\Models\Playlist;
use App\Models\YouTubeMusicAccount;
use App\Services\YouTubeMusic\Client;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Checks the whole library against YouTube Music with a single listing
 * request, and only downloads the tracks of playlists whose fingerprint moved.
 *
 * Playlists that disappeared from the library are flagged, never deleted: the
 * user decides what to do with them.
 */
final readonly class SyncPlaylistsFromYouTubeMusicAction
{
    public function __construct(
        private Client $client,
        private SyncPlaylistTracks $syncTracks,
    ) {}

    /**
     * @param  Closure(int $synced, int $total, ?Playlist $playlist): void|null  $onProgress
     *                                                                                        Called once with `(0, $total, null)` right after the playlist
     *                                                                                        list is fetched, then once per playlist right after it is
     *                                                                                        checked (and its tracks updated, when it changed).
     *
     * @throws YouTubeMusicException When YouTube Music is unreachable or serves the session as signed out (an empty library).
     * @throws YouTubeMusicRateLimitedException When Sonder's own call budget is spent; retry after `retryAfter` seconds.
     */
    public function handle(YouTubeMusicAccount $account, ?Closure $onProgress = null): void
    {
        $summaries = $this->client->playlists($account->cookie);

        if ($summaries === []) {
            throw YouTubeMusicException::signedOut();
        }

        $total = count($summaries);

        $onProgress?->__invoke(0, $total, null);

        $stored = $account->playlists()->get()->keyBy('youtube_playlist_id');

        foreach ($summaries as $index => $summary) {
            $playlist = $stored->get($summary->id) ?? new Playlist([
                'youtube_music_account_id' => $account->id,
                'youtube_playlist_id' => $summary->id,
            ]);

            $this->syncPlaylist($account, $playlist, $summary);

            $onProgress?->__invoke($index + 1, $total, $playlist);
        }

        $this->flagRemovedPlaylists($account, $summaries);
    }

    private function syncPlaylist(YouTubeMusicAccount $account, Playlist $playlist, PlaylistSummaryData $summary): void
    {
        $fingerprint = $summary->fingerprint();
        $isUnchanged = $playlist->exists && $playlist->fingerprint === $fingerprint;

        $playlist->fill([
            'title' => $summary->title,
            'description' => $summary->description,
            'track_count' => $summary->trackCount,
            'thumbnail_url' => $summary->thumbnailUrl,
            'author' => $summary->author,
            'fingerprint' => $fingerprint,
            'last_checked_at' => now(),
            'removed_at' => null,
        ]);

        if ($isUnchanged) {
            $playlist->save();

            return;
        }

        $detailsChanged = ! $playlist->exists
            || $playlist->isDirty(['title', 'description', 'track_count', 'thumbnail_url', 'author']);

        $playlistData = $this->client->playlist($account->cookie, $summary->id, $summary->trackCount);

        DB::transaction(function () use ($playlist, $playlistData, $detailsChanged): void {
            if ($detailsChanged) {
                $playlist->last_changed_at = now();
            }

            $playlist->save();

            $this->syncTracks->handle($playlist, $playlistData);
        });
    }

    /**
     * @param  non-empty-array<int, PlaylistSummaryData>  $summaries
     */
    private function flagRemovedPlaylists(YouTubeMusicAccount $account, array $summaries): void
    {
        $account->playlists()
            ->whereNotIn('youtube_playlist_id', array_map(fn (PlaylistSummaryData $summary): string => $summary->id, $summaries))
            ->whereNull('removed_at')
            ->update(['removed_at' => now()]);
    }
}
