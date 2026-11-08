<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\CatalogAlbumData;
use App\Data\CatalogArtistData;
use App\Data\CatalogPlaylistData;
use App\Data\CatalogTrackData;
use App\Data\SearchResultsData;
use App\Models\Playlist;
use App\Models\Track;
use App\Models\User;
use App\Models\YouTubeMusicAccount;
use App\Services\Music\Data\CatalogAlbumSummary;
use App\Services\Music\Data\CatalogArtistSummary;
use App\Services\Music\Data\CatalogPlaylistSummary;
use App\Services\Music\Data\CatalogResults;
use Illuminate\Database\Eloquent\Builder;

/**
 * Turns catalog search results into page data for one user: library marks,
 * genres, and their own tracks by the top artist.
 */
final readonly class BuildSearchResults
{
    private const int SUGGESTED_TRACKS = 3;

    private const int SUGGESTED_ALBUMS = 2;

    private const int LIBRARY_TRACKS = 3;

    public function __construct(
        private BuildCatalogTracks $buildCatalogTracks,
        private TrackGenres $trackGenres,
        private DescribeArtistInLibrary $describeArtistInLibrary,
        private CountAlbumTracks $countAlbumTracks,
    ) {}

    /**
     * @param  bool  $suggestions  Trims every group to what the command palette shows, with album track counts read from the cache only.
     */
    public function handle(User $user, CatalogResults $results, bool $suggestions = false): SearchResultsData
    {
        $tracks = $suggestions ? array_slice($results->tracks, 0, self::SUGGESTED_TRACKS) : $results->tracks;
        $albums = $suggestions ? array_slice($results->albums, 0, self::SUGGESTED_ALBUMS) : $results->albums;
        $artists = $suggestions ? array_slice($results->artists, 0, 1) : $results->artists;
        $playlists = $suggestions ? array_slice($results->playlists, 0, 1) : $results->playlists;

        $counts = $suggestions
            ? $this->countAlbumTracks->handle(array_map(fn (CatalogAlbumSummary $album): string => $album->ref->externalId, $albums), cachedOnly: true)
            : [];

        $topArtist = $results->topArtist;

        return new SearchResultsData(
            topArtist: $topArtist instanceof CatalogArtistSummary ? CatalogArtistData::fromSummary($topArtist) : null,
            topArtistInLibrary: $topArtist instanceof CatalogArtistSummary ? $this->describeArtistInLibrary->handle($user, $topArtist->name) : null,
            topArtistLibraryTracks: $topArtist instanceof CatalogArtistSummary ? $this->libraryTracks($user, $topArtist->name) : [],
            tracks: $this->buildCatalogTracks->handle($user, $tracks),
            albums: array_map(fn (CatalogAlbumSummary $album): CatalogAlbumData => CatalogAlbumData::fromSummary($album, $counts[$album->ref->externalId] ?? null), $albums),
            artists: array_map(CatalogArtistData::fromSummary(...), $artists),
            playlists: array_map(fn (CatalogPlaylistSummary $playlist): CatalogPlaylistData => CatalogPlaylistData::fromSummary($playlist), $playlists),
        );
    }

    /**
     * The user's most recently added tracks by the artist.
     *
     * @return list<CatalogTrackData>
     */
    private function libraryTracks(User $user, string $artistName): array
    {
        $name = mb_strtolower(mb_trim($artistName));

        $owned = Track::query()
            ->whereIn('playlist_id', Playlist::query()->select('id')->whereIn(
                'youtube_music_account_id',
                YouTubeMusicAccount::query()->select('id')->where('user_id', $user->id),
            ))
            ->where(fn (Builder $credits): Builder => $credits
                ->whereRaw('lower(artists) = ?', [$name])
                ->orWhereRaw("lower(artists) like ? escape '\\'", [addcslashes($name, '\\%_').',%']))
            ->latest()
            ->latest('id')
            ->get()
            ->unique(fn (Track $track): string => $track->youtube_video_id ?? 'row:'.$track->id)
            ->take(self::LIBRARY_TRACKS);

        $genres = $this->trackGenres->handle(array_values(array_filter($owned->pluck('youtube_video_id')->all(), is_string(...))));

        return array_values($owned->map(fn (Track $track): CatalogTrackData => new CatalogTrackData(
            videoId: $track->youtube_video_id,
            title: $track->title,
            artists: $track->artists,
            album: $track->album,
            duration: $track->duration,
            durationSeconds: $track->duration_seconds,
            thumbnailUrl: $track->thumbnail_url,
            isExplicit: $track->is_explicit,
            isAvailable: $track->is_available,
            genres: $genres[$track->youtube_video_id ?? ''] ?? [],
            artistId: null,
            albumId: null,
            year: null,
            plays: null,
            inLibrary: true,
        ))->all());
    }
}
