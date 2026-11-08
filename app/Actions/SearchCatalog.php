<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\CatalogFilter;
use App\Enums\Provider;
use App\Exceptions\Providers\ProviderException;
use App\Services\Music\Contracts\BrowsesCatalog;
use App\Services\Music\Data\CatalogResults;
use App\Services\Music\ProviderRegistry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Searches the YouTube Music catalog, remembering each normalized query for ten minutes.
 */
final readonly class SearchCatalog
{
    public function __construct(private ProviderRegistry $providers) {}

    /**
     * @throws ProviderException
     */
    public function handle(string $query, ?CatalogFilter $filter = null, int $limit = 20): CatalogResults
    {
        $normalized = (string) Str::of($query)->squish()->lower();

        if ($normalized === '') {
            return new CatalogResults(null, [], [], [], []);
        }

        $key = sprintf('catalog:search:%s:%s:%d', hash('xxh128', $normalized), $filter instanceof CatalogFilter ? $filter->value : 'all', $limit);

        return Cache::remember(
            $key,
            now()->addMinutes(10),
            fn (): CatalogResults => $this->providers
                ->require(Provider::YouTubeMusic, BrowsesCatalog::class)
                ->search($normalized, $filter, $limit),
        );
    }
}
