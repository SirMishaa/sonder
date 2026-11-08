<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\DescribePublicPlaylist;
use App\Actions\MatchLibraryTracks;
use App\Actions\TrackGenres;
use App\Data\CatalogPlaylistData;
use App\Data\CatalogTrackData;
use App\Data\PublicPlaylistPageData;
use App\Exceptions\Providers\ProviderException;
use App\Models\User;
use App\Services\Music\Data\RemoteTrack;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

final readonly class BrowsePlaylistController
{
    public function show(string $playlistId, #[CurrentUser] User $user, DescribePublicPlaylist $describe, MatchLibraryTracks $match, TrackGenres $trackGenres): Response|RedirectResponse
    {
        $inLibrary = $user->youTubeMusicAccount()->first()?->playlists()
            ->where('youtube_playlist_id', $playlistId)
            ->exists();

        if ($inLibrary === true) {
            return to_route('playlist.show', $playlistId);
        }

        try {
            $playlist = $describe->handle($playlistId);
        } catch (ProviderException $exception) {
            return Inertia::render('browse-playlist/Show', ['page' => null, 'catalogError' => $exception->userMessage()]);
        }

        $matches = $match->handle($user, $playlist->tracks);
        $genres = $trackGenres->handle(array_values(array_filter(
            array_map(fn (RemoteTrack $track): ?string => $track->ref?->externalId, $playlist->tracks),
            is_string(...),
        )));

        $tracks = [];

        foreach ($playlist->tracks as $index => $track) {
            $tracks[] = CatalogTrackData::fromRemote($track, $genres[$track->ref->externalId ?? ''] ?? [], $matches[$index] ?? false);
        }

        return Inertia::render('browse-playlist/Show', [
            'page' => new PublicPlaylistPageData(
                playlist: CatalogPlaylistData::fromRemote($playlist->summary),
                description: $playlist->summary->description,
                tracks: $tracks,
            ),
        ]);
    }
}
