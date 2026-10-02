<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\CheckLibraryFreshness;
use App\Actions\SampleLibraryTracks;
use App\Actions\StartYouTubeMusicSync;
use App\Actions\TrackGenres;
use App\Data\PlaylistData;
use App\Data\PlaylistSummaryData;
use App\Data\PlaylistSyncStateData;
use App\Data\TrackData;
use App\Models\Track;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

final readonly class PlaylistController
{
    private const int SUGGESTIONS = 5;

    public function index(
        #[CurrentUser] User $user,
        StartYouTubeMusicSync $startSync,
        CheckLibraryFreshness $checkFreshness,
    ): Response|RedirectResponse {
        $account = $user->youTubeMusicAccount()->first();

        if ($account === null) {
            return to_route('youtube-music-connection.create');
        }

        if ($account->playlists()->doesntExist()) {
            return to_route('youtube-music-connection.sync', $startSync->handle($account));
        }

        $checkFreshness->handle($account);

        return Inertia::render('playlist/Index');
    }

    public function show(string $playlistId, #[CurrentUser] User $user, SampleLibraryTracks $sample, TrackGenres $trackGenres): Response|RedirectResponse
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

        $genres = $trackGenres->handle(array_values(array_filter($playlist->tracks->pluck('youtube_video_id')->all(), is_string(...))));
        $tracks = $playlist->tracks->map(fn (Track $track): TrackData => TrackData::fromModel($track, $genres[$track->youtube_video_id ?? ''] ?? []));

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
            'suggestionPool' => Inertia::defer(fn () => $sample->handle($account, self::SUGGESTIONS, $playlist)),
        ]);
    }
}
