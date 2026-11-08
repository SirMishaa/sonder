<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\BuildSearchResults;
use App\Actions\SearchCatalog;
use App\Exceptions\Providers\ProviderException;
use App\Http\Requests\ShowSearchRequest;
use App\Models\User;
use App\Services\Music\Data\CatalogResults;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final readonly class SearchSuggestionController
{
    private const int LIMIT = 8;

    public function index(ShowSearchRequest $request, #[CurrentUser] User $user, SearchCatalog $search, BuildSearchResults $build): JsonResponse
    {
        $query = $request->searchText();

        if ($query === '') {
            return response()->json($build->handle($user, new CatalogResults(null, [], [], [], []), suggestions: true));
        }

        try {
            return response()->json($build->handle($user, $search->handle($query, null, self::LIMIT), suggestions: true));
        } catch (ProviderException $exception) {
            return response()->json(['message' => $exception->userMessage()], 503);
        }
    }
}
