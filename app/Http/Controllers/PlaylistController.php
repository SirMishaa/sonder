<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\SyncPlaylistsFromYouTubeMusicAction;
use App\Data\PlaylistData;
use App\Data\PlaylistSummaryData;
use App\Data\TrackData;
use App\Exceptions\YouTubeMusicException;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

final readonly class PlaylistController
{
    public function index(#[CurrentUser] User $user, SyncPlaylistsFromYouTubeMusicAction $sync): Response|RedirectResponse
    {
        $account = $user->youTubeMusicAccount()->first();

        if ($account === null) {
            return to_route('youtube-music-connection.create');
        }

        if ($account->playlists()->count() === 0 || $account->playlists()->max('last_synced_at') < now()->subHour()) {
            try {
                $sync->handle($account);
            } catch (YouTubeMusicException) {
                return $this->expired();
            }
        }

        $playlists = $account->playlists()->get()->map(
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
        ]);
    }

    private function expired(): RedirectResponse
    {
        return to_route('youtube-music-connection.create')->withErrors([
            'cookie' => 'The stored cookie no longer works. YouTube Music cookies expire after a few weeks; paste a fresh one.',
        ]);
    }
}
