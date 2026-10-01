<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\Providers\ProviderException;
use App\Models\Playlist;
use App\Models\YouTubeMusicAccount;
use App\Services\Music\Contracts\ProviderCredentials;
use App\Services\Music\Contracts\ReadsPlaylists;
use App\Services\Music\Data\RemotePlaylistSummary;
use App\Services\Music\ProviderRegistry;
use App\Services\Thumbnails\ThumbnailProxy;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Checks the whole library against the provider with a single listing
 * request, and only downloads the tracks of playlists whose fingerprint moved.
 *
 * Playlists that disappeared from the library are flagged, never deleted: the
 * user decides what to do with them.
 */
final readonly class SyncPlaylistsFromYouTubeMusicAction
{
    public function __construct(
        private ProviderRegistry $providers,
        private SyncPlaylistTracks $syncTracks,
    ) {}

    /**
     * @param  Closure(int $synced, int $total, ?Playlist $playlist): void|null  $onProgress
     *                                                                                        Called once with `(0, $total, null)` right after the playlist
     *                                                                                        list is fetched, then once per playlist right after it is
     *                                                                                        checked (and its tracks updated, when it changed).
     *
     * @throws ProviderException When the provider refuses the session, cannot be reached, or Sonder's call budget is spent.
     */
    public function handle(YouTubeMusicAccount $account, ?Closure $onProgress = null): void
    {
        $playlists = $this->providers->require($account->provider(), ReadsPlaylists::class);
        $credentials = $account->credentials();

        // The adapter raises CredentialsRejected for a signed-out session, so
        // an empty list here is a genuinely empty library.
        $summaries = $playlists->playlists($credentials);

        $total = count($summaries);

        $onProgress?->__invoke(0, $total, null);

        $stored = $account->playlists()->get()->keyBy('youtube_playlist_id');

        foreach ($summaries as $index => $summary) {
            $playlist = $stored->get($summary->ref->externalId) ?? new Playlist([
                'youtube_music_account_id' => $account->id,
                'youtube_playlist_id' => $summary->ref->externalId,
            ]);

            $this->syncPlaylist($playlists, $credentials, $playlist, $summary);

            $onProgress?->__invoke($index + 1, $total, $playlist);
        }

        $this->flagRemovedPlaylists($account, $summaries);
    }

    /**
     * Refreshes the playlist's listing details and, when its fingerprint
     * moved, downloads and reconciles its tracks.
     *
     * @throws ProviderException
     */
    private function syncPlaylist(ReadsPlaylists $playlists, ProviderCredentials $credentials, Playlist $playlist, RemotePlaylistSummary $summary): void
    {
        $fingerprint = $summary->fingerprint();
        $isUnchanged = $playlist->exists && $playlist->fingerprint === $fingerprint;

        $playlist->fill([
            'title' => $summary->title,
            'description' => $summary->description,
            'track_count' => $summary->trackCount,
            'thumbnail_url' => ThumbnailProxy::url($summary->thumbnailUrl),
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

        $playlistData = $playlists->playlist($credentials, $summary->ref->externalId, $summary->trackCount);

        DB::transaction(function () use ($playlist, $playlistData, $detailsChanged): void {
            if ($detailsChanged) {
                $playlist->last_changed_at = now();
            }

            $playlist->save();

            $this->syncTracks->handle($playlist, $playlistData);
        });
    }

    /**
     * @param  list<RemotePlaylistSummary>  $summaries
     */
    private function flagRemovedPlaylists(YouTubeMusicAccount $account, array $summaries): void
    {
        $account->playlists()
            ->whereNotIn('youtube_playlist_id', array_map(fn (RemotePlaylistSummary $summary): string => $summary->ref->externalId, $summaries))
            ->whereNull('removed_at')
            ->update(['removed_at' => now()]);
    }
}
