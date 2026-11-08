<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\AlbumPageData;
use App\Data\CatalogAlbumData;
use App\Data\CatalogTrackData;
use App\Exceptions\Providers\ProviderException;
use App\Models\User;

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
        private BuildCatalogTracks $buildCatalogTracks,
    ) {}

    /**
     * @throws ProviderException
     */
    public function handle(User $user, string $albumId): AlbumPageData
    {
        $album = $this->describeAlbum->handle($albumId);
        $tracks = $this->buildCatalogTracks->handle($user, $album->tracks, $album->playsByVideoId);

        $artist = $album->summary->artists[0] ?? null;

        return new AlbumPageData(
            album: CatalogAlbumData::fromSummary($album->summary, $album->trackCount),
            description: $album->description,
            durationSeconds: $album->durationSeconds,
            totalPlays: array_sum($album->playsByVideoId),
            genres: $artist === null ? [] : $this->artistGenres->handle($artist->name),
            inLibraryCount: count(array_filter($tracks, fn (CatalogTrackData $track): bool => $track->inLibrary)),
            tracks: $tracks,
        );
    }
}
