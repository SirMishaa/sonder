<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\BuildAlbumPage;
use App\Actions\CountAlbumTracks;
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
    public function show(string $albumId, #[CurrentUser] User $user, BuildAlbumPage $build, DescribeArtist $describeArtist, CountAlbumTracks $countAlbumTracks): Response
    {
        try {
            $page = $build->handle($user, $albumId);
        } catch (ProviderException $exception) {
            return Inertia::render('album/Show', ['page' => null, 'catalogError' => $exception->userMessage()]);
        }

        return Inertia::render('album/Show', [
            'page' => $page,
            'moreByArtist' => Inertia::defer(fn (): array => $this->moreByArtist($page, $describeArtist, $countAlbumTracks)),
        ]);
    }

    /**
     * The artist's other albums and singles, track counts from the cache only.
     *
     * @return list<CatalogAlbumData>
     */
    private function moreByArtist(AlbumPageData $page, DescribeArtist $describeArtist, CountAlbumTracks $countAlbumTracks): array
    {
        if ($page->album->artistId === null) {
            return [];
        }

        try {
            $artist = $describeArtist->handle($page->album->artistId);
        } catch (ProviderException) {
            return [];
        }

        $others = array_values(array_filter(
            [...$artist->albums, ...$artist->singles],
            fn (CatalogAlbumSummary $album): bool => $album->ref->externalId !== $page->album->id,
        ));
        $counts = $countAlbumTracks->handle(array_map(fn (CatalogAlbumSummary $album): string => $album->ref->externalId, $others), cachedOnly: true);

        return array_map(fn (CatalogAlbumSummary $album): CatalogAlbumData => CatalogAlbumData::fromSummary($album, $counts[$album->ref->externalId] ?? null), $others);
    }
}
