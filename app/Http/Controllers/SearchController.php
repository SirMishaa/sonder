<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\BuildSearchResults;
use App\Actions\CountAlbumTracks;
use App\Actions\SearchCatalog;
use App\Data\SearchResultsData;
use App\Exceptions\Providers\ProviderException;
use App\Http\Requests\ShowSearchRequest;
use App\Models\User;
use App\Services\Music\Data\CatalogAlbumSummary;
use Illuminate\Container\Attributes\CurrentUser;
use Inertia\Inertia;
use Inertia\Response;

final readonly class SearchController
{
    private const int PAGE_LIMIT = 20;

    public function index(
        ShowSearchRequest $request,
        #[CurrentUser] User $user,
        SearchCatalog $search,
        BuildSearchResults $build,
        CountAlbumTracks $countAlbumTracks,
    ): Response {
        $query = $request->searchText();
        $filter = $request->filter();

        $props = [
            'query' => $query,
            'type' => $filter?->value,
        ];

        if ($query === '') {
            return Inertia::render('search/Index', [...$props, 'results' => null]);
        }

        // Both deferred props come from one provider call.
        $outcome = null;
        $resolve = function () use (&$outcome, $search, $build, $user, $query, $filter): array {
            if ($outcome !== null) {
                return $outcome;
            }

            try {
                return $outcome = ['results' => $build->handle($user, $search->handle($query, $filter, self::PAGE_LIMIT)), 'error' => null];
            } catch (ProviderException $exception) {
                return $outcome = ['results' => null, 'error' => $exception->userMessage()];
            }
        };

        return Inertia::render('search/Index', [
            ...$props,
            'results' => Inertia::defer(fn (): ?SearchResultsData => $resolve()['results']),
            'catalogError' => Inertia::defer(fn (): ?string => $resolve()['error']),
            'albumTrackCounts' => Inertia::defer(function () use ($search, $countAlbumTracks, $query, $filter): array {
                try {
                    $albums = $search->handle($query, $filter, self::PAGE_LIMIT)->albums;
                } catch (ProviderException) {
                    return [];
                }

                return $countAlbumTracks->handle(array_map(fn (CatalogAlbumSummary $album): string => $album->ref->externalId, $albums));
            }, 'counts'),
        ]);
    }
}
