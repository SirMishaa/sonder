<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\AlbumPageData;
use App\Data\CatalogAlbumData;
use App\Data\CatalogTrackData;
use App\Exceptions\Providers\ProviderException;
use App\Models\User;
use App\Services\Music\Data\RemoteTrack;

/**
 * Composes an album page for one user: the catalog album with plays,
 * genres and library marks. Only the year is shown of the release; label
 * and date have no stored source yet.
 */
final readonly class BuildAlbumPage
{
    public function __construct(
        private DescribeAlbum $describeAlbum,
        private ArtistGenres $artistGenres,
        private MatchLibraryTracks $matchLibraryTracks,
        private TrackGenres $trackGenres,
    ) {}

    /**
     * @throws ProviderException
     */
    public function handle(User $user, string $albumId): AlbumPageData
    {
        $album = $this->describeAlbum->handle($albumId);
        $inLibrary = $this->matchLibraryTracks->handle($user, $album->tracks);
        $genres = $this->trackGenres->handle(array_values(array_filter(
            array_map(fn (RemoteTrack $track): ?string => $track->ref?->externalId, $album->tracks),
            is_string(...),
        )));

        $tracks = [];

        foreach ($album->tracks as $index => $track) {
            $videoId = $track->ref->externalId ?? '';

            $tracks[] = CatalogTrackData::fromRemote($track, $genres[$videoId] ?? [], $inLibrary[$index] ?? false, $album->playsByVideoId[$videoId] ?? null);
        }

        $artist = $album->summary->artists[0] ?? null;

        return new AlbumPageData(
            album: CatalogAlbumData::fromSummary($album->summary, $album->trackCount),
            description: $album->description,
            durationSeconds: $album->durationSeconds,
            totalPlays: array_sum($album->playsByVideoId),
            genres: $artist === null ? [] : $this->artistGenres->handle($artist->name),
            inLibraryCount: count(array_filter($inLibrary)),
            tracks: $tracks,
        );
    }
}
