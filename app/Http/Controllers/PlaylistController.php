<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\StartYouTubeMusicSync;
use App\Data\PlaylistData;
use App\Data\PlaylistSummaryData;
use App\Data\PlaylistSyncStateData;
use App\Data\TrackData;
use App\Data\YouTubeMusicSyncData;
use App\Enums\YouTubeMusicSyncStatus;
use App\Models\Playlist;
use App\Models\User;
use App\Models\YouTubeMusicSync;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

final readonly class PlaylistController
{
    public function index(#[CurrentUser] User $user, StartYouTubeMusicSync $startSync): Response|RedirectResponse
    {
        $account = $user->youTubeMusicAccount()->first();

        if ($account === null) {
            return to_route('youtube-music-connection.create');
        }

        if ($account->playlists()->doesntExist()) {
            $sync = $startSync->handle($account);

            return to_route('youtube-music-connection.sync', $sync);
        }

        $activeSync = $account->playlists()->max('last_checked_at') < now()->subHour()
            ? $startSync->handle($account)
            : YouTubeMusicSync::query()
                ->where('youtube_music_account_id', $account->id)
                ->whereIn('status', [YouTubeMusicSyncStatus::Pending, YouTubeMusicSyncStatus::Syncing])
                ->latest('created_at')
                ->first();

        // `StartYouTubeMusicSync::handle()` returns the in-memory instance
        // created before the job was dispatched. Under the sync queue driver
        // the job runs inline and mutates the DB row immediately, so this
        // refresh keeps the rendered sync state current in every environment.
        $activeSync?->refresh();

        $storedPlaylists = $account->playlists()->get();

        $playlists = $storedPlaylists->map(
            fn (Playlist $playlist) => PlaylistSummaryData::from([
                'id' => $playlist->youtube_playlist_id,
                'title' => $playlist->title,
                'description' => $playlist->description,
                'trackCount' => $playlist->track_count,
                'thumbnailUrl' => $playlist->thumbnail_url,
                'author' => $playlist->author,
            ])
        );

        return Inertia::render('playlist/Index', [
            'accountName' => $account->account_name,
            'playlists' => $playlists,
            'removedPlaylistIds' => $storedPlaylists
                ->whereNotNull('removed_at')
                ->pluck('youtube_playlist_id')
                ->values(),
            'lastCheckedAt' => $storedPlaylists->max('last_checked_at')?->toIso8601String(),
            'activeSync' => $activeSync !== null ? YouTubeMusicSyncData::fromModel($activeSync) : null,
        ]);
    }

    public function show(string $playlistId, #[CurrentUser] User $user): Response|RedirectResponse
    {
        $account = $user->youTubeMusicAccount()->first();

        if ($account === null) {
            return to_route('youtube-music-connection.create');
        }

        $playlist = $account->playlists()
            ->where('youtube_playlist_id', $playlistId)
            ->with('tracks')
            ->first();

        if ($playlist === null) {
            return to_route('playlist.index');
        }

        $summary = PlaylistSummaryData::from([
            'id' => $playlist->youtube_playlist_id,
            'title' => $playlist->title,
            'description' => $playlist->description,
            'trackCount' => $playlist->track_count,
            'thumbnailUrl' => $playlist->thumbnail_url,
            'author' => $playlist->author,
        ]);

        $tracks = $playlist->tracks->map(fn ($track) => TrackData::from([
            'videoId' => $track->youtube_video_id,
            'title' => $track->title,
            'artists' => $track->artists,
            'album' => $track->album,
            'duration' => $track->duration,
            'durationSeconds' => $track->duration_seconds,
            'thumbnailUrl' => $track->thumbnail_url,
            'isExplicit' => $track->is_explicit,
            'isAvailable' => $track->is_available,
        ]));

        $playlistData = PlaylistData::from([
            'id' => $playlist->youtube_playlist_id,
            'title' => $playlist->title,
            'description' => $playlist->description,
            'trackCount' => $playlist->track_count,
            'duration' => $playlist->duration,
            'thumbnailUrl' => $playlist->thumbnail_url,
            'author' => $playlist->author,
            'tracks' => $tracks->toArray(),
        ]);

        return Inertia::render('playlist/Show', [
            'playlistId' => $playlistId,
            'summary' => $summary,
            'playlist' => $playlistData,
            'syncState' => PlaylistSyncStateData::fromModel($playlist),
        ]);
    }
}
