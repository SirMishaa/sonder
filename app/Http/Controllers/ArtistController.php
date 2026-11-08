<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\BuildArtistPage;
use App\Actions\CountAlbumTracks;
use App\Actions\DescribeArtist;
use App\Exceptions\Providers\ProviderException;
use App\Models\User;
use App\Services\Music\Data\CatalogAlbumSummary;
use Illuminate\Container\Attributes\CurrentUser;
use Inertia\Inertia;
use Inertia\Response;

final readonly class ArtistController
{
    public function show(string $artistId, #[CurrentUser] User $user, BuildArtistPage $build, DescribeArtist $describe, CountAlbumTracks $countAlbumTracks): Response
    {
        try {
            $page = $build->handle($user, $artistId);
        } catch (ProviderException $exception) {
            return Inertia::render('artist/Show', ['page' => null, 'catalogError' => $exception->userMessage()]);
        }

        return Inertia::render('artist/Show', [
            'page' => $page,
            'albumTrackCounts' => Inertia::defer(function () use ($describe, $countAlbumTracks, $artistId): array {
                try {
                    $artist = $describe->handle($artistId);
                } catch (ProviderException) {
                    return [];
                }

                return $countAlbumTracks->handle(array_map(
                    fn (CatalogAlbumSummary $album): string => $album->ref->externalId,
                    [...$artist->albums, ...$artist->singles],
                ));
            }),
        ]);
    }
}
