<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\BuildAlbumPage;
use App\Actions\CountAlbumTracks;
use App\Actions\DescribeAlbum;
use App\Actions\DescribeArtist;
use App\Data\AlbumPageData;
use App\Data\CatalogAlbumData;
use App\Exceptions\Providers\ProviderException;
use App\Models\User;
use App\Services\Music\Data\CatalogAlbumSummary;
use Illuminate\Container\Attributes\CurrentUser;
use Inertia\Inertia;
use Inertia\Response;

final readonly class AlbumController
{
    public function show(string $albumId, #[CurrentUser] User $user, BuildAlbumPage $build, DescribeAlbum $describeAlbum, DescribeArtist $describeArtist, CountAlbumTracks $countAlbumTracks): Response
    {
        // `page` and `catalogError` share one build, and a partial reload that asks for neither skips it.
        $outcome = null;
        $resolve = function () use (&$outcome, $build, $user, $albumId): array {
            if ($outcome !== null) {
                return $outcome;
            }

            try {
                return $outcome = ['page' => $build->handle($user, $albumId), 'error' => null];
            } catch (ProviderException $exception) {
                return $outcome = ['page' => null, 'error' => $exception->userMessage()];
            }
        };

        return Inertia::render('album/Show', [
            'page' => fn (): ?AlbumPageData => $resolve()['page'],
            'catalogError' => fn (): ?string => $resolve()['error'],
            'moreByArtist' => Inertia::defer(function () use ($albumId, $describeAlbum, $describeArtist, $countAlbumTracks): array {
                return $this->moreByArtist($albumId, $describeAlbum, $describeArtist, $countAlbumTracks);
            }),
        ]);
    }

    /**
     * The artist's other albums and singles, track counts from the cache only.
     *
     * @return list<CatalogAlbumData>
     */
    private function moreByArtist(string $albumId, DescribeAlbum $describeAlbum, DescribeArtist $describeArtist, CountAlbumTracks $countAlbumTracks): array
    {
        try {
            $current = $describeAlbum->handle($albumId)->summary;
            $artistId = ($current->artists[0] ?? null)?->ref?->externalId;

            if ($artistId === null) {
                return [];
            }

            $artist = $describeArtist->handle($artistId);
        } catch (ProviderException) {
            return [];
        }

        $others = array_values(array_filter(
            [...$artist->albums, ...$artist->singles],
            fn (CatalogAlbumSummary $album): bool => $album->ref->externalId !== $albumId,
        ));
        $counts = $countAlbumTracks->handle(array_map(fn (CatalogAlbumSummary $album): string => $album->ref->externalId, $others), cachedOnly: true);

        return array_map(fn (CatalogAlbumSummary $album): CatalogAlbumData => CatalogAlbumData::fromSummary($album, $counts[$album->ref->externalId] ?? null), $others);
    }
}
