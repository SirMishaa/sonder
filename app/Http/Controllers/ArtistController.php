<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\BuildArtistPage;
use App\Actions\CountAlbumTracks;
use App\Actions\DescribeArtist;
use App\Data\ArtistPageData;
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
        // `page` and `catalogError` share one build, and a partial reload that asks for neither skips it.
        $outcome = null;
        $resolve = function () use (&$outcome, $build, $user, $artistId): array {
            if ($outcome !== null) {
                return $outcome;
            }

            try {
                return $outcome = ['page' => $build->handle($user, $artistId), 'error' => null];
            } catch (ProviderException $exception) {
                return $outcome = ['page' => null, 'error' => $exception->userMessage()];
            }
        };

        return Inertia::render('artist/Show', [
            'page' => fn (): ?ArtistPageData => $resolve()['page'],
            'catalogError' => fn (): ?string => $resolve()['error'],
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
            }, 'counts'),
        ]);
    }
}
