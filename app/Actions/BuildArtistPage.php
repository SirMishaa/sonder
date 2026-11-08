<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\ArtistPageData;
use App\Data\CatalogAlbumData;
use App\Data\CatalogArtistData;
use App\Data\CatalogVideoData;
use App\Exceptions\Providers\ProviderException;
use App\Models\User;
use App\Services\Music\Data\CatalogAlbum;
use App\Services\Music\Data\CatalogAlbumSummary;
use App\Services\Music\Data\CatalogArtist;
use App\Services\Music\Data\CatalogVideo;
use App\Services\Music\Data\RemoteTrack;
use Illuminate\Support\Facades\Cache;

/**
 * Composes an artist page for one user: the catalog profile with library
 * stats, genres and library marks on the top tracks.
 */
final readonly class BuildArtistPage
{
    public function __construct(
        private DescribeArtist $describeArtist,
        private DescribeArtistInLibrary $describeArtistInLibrary,
        private ArtistGenres $artistGenres,
        private BuildCatalogTracks $buildCatalogTracks,
    ) {}

    /**
     * @throws ProviderException
     */
    public function handle(User $user, string $artistId): ArtistPageData
    {
        return $this->compose($user, $this->describeArtist->handle($artistId));
    }

    private function compose(User $user, CatalogArtist $artist): ArtistPageData
    {
        return new ArtistPageData(
            artist: CatalogArtistData::fromSummary($artist->summary),
            monthlyListeners: $artist->monthlyListeners,
            views: $artist->views,
            description: $artist->description,
            genres: $this->artistGenres->handle($artist->summary->name),
            tracks: $this->buildCatalogTracks->handle($user, array_map(fn (RemoteTrack $track): RemoteTrack => $this->withDuration($track, $artist), $artist->topTracks)),
            albums: array_map(fn (CatalogAlbumSummary $album): CatalogAlbumData => CatalogAlbumData::fromSummary($album), $artist->albums),
            singles: array_map(fn (CatalogAlbumSummary $album): CatalogAlbumData => CatalogAlbumData::fromSummary($album), $artist->singles),
            videos: array_map(fn (CatalogVideo $video): CatalogVideoData => CatalogVideoData::fromCatalog($video), $artist->videos),
            related: array_map(CatalogArtistData::fromSummary(...), $artist->related),
            inLibrary: $this->describeArtistInLibrary->handle($user, $artist->summary->name),
        );
    }

    /**
     * Artist top tracks come without a duration; an album already in the
     * cache that holds the same video supplies it, else it stays null.
     */
    private function withDuration(RemoteTrack $track, CatalogArtist $artist): RemoteTrack
    {
        if ($track->durationSeconds !== null || $track->ref === null) {
            return $track;
        }

        $albumIds = array_map(
            fn (CatalogAlbumSummary $album): string => $album->ref->externalId,
            [...$artist->albums, ...$artist->singles],
        );

        if ($track->album?->ref !== null) {
            array_unshift($albumIds, $track->album->ref->externalId);
        }

        foreach ($albumIds as $albumId) {
            $album = Cache::get(DescribeAlbum::cacheKey($albumId));

            if (! $album instanceof CatalogAlbum) {
                continue;
            }

            foreach ($album->tracks as $candidate) {
                if ($candidate->ref?->externalId === $track->ref->externalId && $candidate->durationSeconds !== null) {
                    return new RemoteTrack(
                        $track->ref,
                        $track->title,
                        $track->artists,
                        $track->album,
                        $candidate->durationSeconds,
                        $track->isrc,
                        $track->kind,
                        $track->isExplicit,
                        $track->isAvailable,
                        $track->thumbnailUrl,
                    );
                }
            }
        }

        return $track;
    }
}
